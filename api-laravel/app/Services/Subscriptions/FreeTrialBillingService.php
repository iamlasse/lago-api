<?php

declare(strict_types=1);

namespace App\Services\Subscriptions;

use App\Enums\FeeType;
use App\Enums\InvoiceType;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use App\Enums\InvoiceStatus;
use App\Jobs\SendWebhookJob;
use App\Models\Subscription;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Jobs\BillSubscriptionJob;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' Subscriptions::FreeTrialBillingService
 * (app/services/subscriptions/free_trial_billing_service.rb) — the hourly
 * clock pass that closes out active subscriptions whose free trial just
 * ended: bills the plan upfront (pay-in-advance), stamps trial_ended_at and
 * emits subscription.trial_ended.
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): Hubspot sync (should_sync_hubspot_subscription?).
 */
class FreeTrialBillingService extends BaseService
{
    public function __construct(
        protected CarbonInterface $timestamp,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult();

        foreach ($this->endingTrialSubscriptions() as $subscription) {
            if (! $subscription->was_already_billed_today
                && ! $this->alreadyBilledOnDayOne($subscription)) {
                if ($subscription->plan->pay_in_advance) {
                    BillSubscriptionJob::dispatch(
                        [$subscription],
                        (int) $this->timestamp->getTimestamp(),
                        'subscription_starting',
                        null,
                        true,
                    );
                }
            }

            $subscription->trial_ended_at = $subscription->trial_end_utc_date_from_query;
            $subscription->save();

            SendWebhookJob::performLater('subscription.trial_ended', $subscription);

            // TODO(port): Hubspot UpdateJob when
            //   subscription.should_sync_hubspot_subscription?.
        }

        return $result;
    }

    /** Rails: at_time_zone (Utils::Timezone.at_time_zone_sql). */
    protected static function atTimeZone(string $customer = 'customers', string $billingEntity = 'billing_entities'): string
    {
        return "::timestamptz AT TIME ZONE COALESCE({$customer}.timezone, {$billingEntity}.timezone, 'UTC')";
    }

    // -- Queries ----------------------------------------------------------------------

    /**
     * Rails: `ending_trial_subscriptions` — find_by_sql over the same
     * statement, hydrating Subscription rows with the query's extra
     * attributes (plan_pay_in_advance, was_already_billed_today,
     * trial_end_utc_date_from_query).
     *
     * @return list<Subscription>
     */
    protected function endingTrialSubscriptions(): array
    {
        $atTimeZone = self::atTimeZone();

        $sql = <<<'SQL'
            WITH
              initial_started_at AS (
                SELECT
                  external_id,
                  FIRST_VALUE(started_at) OVER (PARTITION BY external_id ORDER BY started_at) AS initial_started_at
                FROM subscriptions
              ),
              already_billed_today AS (
                SELECT
                  invoice_subscriptions.subscription_id,
                  COUNT(invoice_subscriptions.id) AS invoiced_count
                FROM invoice_subscriptions
                  INNER JOIN subscriptions AS sub ON invoice_subscriptions.subscription_id = sub.id
                  INNER JOIN customers AS cus ON sub.customer_id = cus.id
                  INNER JOIN billing_entities ON cus.billing_entity_id = billing_entities.id
                WHERE invoice_subscriptions.recurring = 't'
                  AND invoice_subscriptions.timestamp IS NOT NULL
                  AND DATE((invoice_subscriptions.timestamp)%AT_CUS%) = DATE(:timestamp%AT_CUS%)
                GROUP BY invoice_subscriptions.subscription_id
              )
            SELECT DISTINCT
              plans.pay_in_advance AS plan_pay_in_advance,
              (already_billed_today.invoiced_count > 0) AS was_already_billed_today,
              (initial_started_at.initial_started_at + plans.trial_period * INTERVAL '1 day') AS trial_end_utc_date_from_query,
              subscriptions.*
            FROM subscriptions
              INNER JOIN plans ON subscriptions.plan_id = plans.id
              INNER JOIN initial_started_at ON initial_started_at.external_id = subscriptions.external_id
              INNER JOIN customers ON subscriptions.customer_id = customers.id
              INNER JOIN billing_entities ON customers.billing_entity_id = billing_entities.id
              LEFT JOIN already_billed_today ON already_billed_today.subscription_id = subscriptions.id
            WHERE subscriptions.status = 1
              AND plans.trial_period > 0
              AND subscriptions.trial_ended_at IS NULL
              AND (initial_started_at.initial_started_at + plans.trial_period * INTERVAL '1 day')%AT% <= :timestamp%AT%
            SQL;

        $sql = str_replace('%AT%', $atTimeZone, $sql);
        $sql = str_replace('%AT_CUS%', self::atTimeZone('cus', 'billing_entities'), $sql);

        $rows = DB::select($sql, ['timestamp' => $this->timestamp->toDateTimeString()]);

        /** @var list<Subscription> */
        return Subscription::hydrate(array_map(fn ($row) => (array) $row, $rows))->all();
    }

    /**
     * This is to avoid billing at the end of the trial if the customer was
     * billed at the beginning. It's only for users who started billing
     * customer AND upgraded their lago with this feature during the customer
     * trial period. Unfortunately, this introduces an N+1 query.
     */
    protected function alreadyBilledOnDayOne(Subscription $subscription): bool
    {
        $invoiceIds = DB::table('invoice_subscriptions')
            ->join('invoices', 'invoice_subscriptions.invoice_id', '=', 'invoices.id')
            ->where('invoice_subscriptions.subscription_id', $subscription->id)
            ->where('invoices.invoice_type', InvoiceType::Subscription->value)
            ->whereIn('invoices.status', [InvoiceStatus::Draft->value, InvoiceStatus::Finalized->value])
            // Rails: :timestamp => subscription.started_at.all_day — the
            // timestamp is the invoice_subscription's, not the invoice's.
            ->whereBetween('invoice_subscriptions.timestamp', [
                CarbonImmutable::instance($subscription->started_at)->startOfDay(),
                CarbonImmutable::instance($subscription->started_at)->endOfDay(),
            ])
            ->select('invoices.id')
            ->pluck('id');

        if ($invoiceIds->isEmpty()) {
            return false;
        }

        return DB::table('fees')
            ->whereIn('invoice_id', $invoiceIds)
            ->where('fee_type', FeeType::Subscription->value)
            ->exists();
    }
}
