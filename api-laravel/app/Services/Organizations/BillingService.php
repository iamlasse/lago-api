<?php

declare(strict_types=1);

namespace App\Services\Organizations;

use App\Enums\BillingTime;
use App\Enums\PlanInterval;
use Carbon\CarbonImmutable;
use App\Models\Organization;
use App\Models\Subscription;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Enums\SubscriptionStatus;
use App\Jobs\BillSubscriptionJob;
use Illuminate\Support\Collection;
use App\Jobs\Subscriptions\TerminateJob;

/**
 * Port of Rails' Subscriptions::OrganizationBillingService
 * (app/services/subscriptions/organization_billing_service.rb) — the
 * hourly biller entry point. A single UNION query computes the
 * billable-today subscriptions per interval × billing time (including the
 * already_billed_today CTE), then subscriptions are grouped per customer
 * (payment method → currency → billing entity → consolidation → PO number)
 * into BillSubscriptionJob dispatches.
 *
 * The query runs as raw SQL (DB::select) exactly like Rails.
 *
 * TODO(port): BillNonInvoiceableFeesJob (recurring non-invoiceable fees).
 */
class BillingService extends BaseService
{
    private CarbonImmutable $today;

    private int $currentBillingTime = 0;

    private int $currentInterval = 0;

    public function __construct(
        private readonly Organization $organization,
        private readonly ?CarbonImmutable $billingAt = null,
    ) {}

    public function execute(): BaseResult
    {
        $result = BaseResult::of();
        $this->today = $this->billingAt ? CarbonImmutable::instance($this->billingAt)->utc() : CarbonImmutable::now('UTC');

        /** @var Collection<int, Subscription> $billable */
        $billable = $this->billableSubscriptions();

        foreach ($billable->groupBy('customer_id') as $customerSubscriptions) {
            $billingSubscriptions = [];

            foreach ($customerSubscriptions as $subscription) {
                $nextSubscription = $subscription->nextSubscription();

                if ($nextSubscription !== null && $nextSubscription->pending()) {
                    // NOTE: In case of downgrade, the subscription remains
                    // active until the end of the period; a next subscription
                    // is pending, the current one must be terminated.
                    dispatch(new \App\Jobs\Subscriptions\TerminateJob($subscription, $this->today->getTimestamp()));
                } else {
                    $billingSubscriptions[] = $subscription;
                }
            }

            if ($billingSubscriptions === []) {
                continue;
            }

            $subscriptionGroups = $this->groupByPaymentMethod($billingSubscriptions);
            $subscriptionGroups = $this->groupByCurrency($subscriptionGroups);
            $subscriptionGroups = $this->groupByBillingEntity($subscriptionGroups);
            $subscriptionGroups = $this->splitConsolidationOptedOut($subscriptionGroups);
            $subscriptionGroups = $this->groupByPurchaseOrderNumber($subscriptionGroups);

            foreach ($subscriptionGroups as $subscriptions) {
                dispatch(new \App\Jobs\BillSubscriptionJob($subscriptions, $this->today->getTimestamp(), 'subscription_periodic'));

                // TODO(port): BillNonInvoiceableFeesJob::dispatch.
            }
        }

        return $result;
    }

    /**
     * Port of Utils::Timezone.at_time_zone_sql — the customer's timezone
     * with billing-entity fallback, applied per table alias.
     */
    private static function atTimeZone(string $customer = 'customers', string $billingEntity = 'billing_entities'): string
    {
        return "::timestamptz AT TIME ZONE COALESCE({$customer}.timezone, {$billingEntity}.timezone, 'UTC')";
    }

    /**
     * The billing-day condition shared by the anniversary branches: on the
     * last day of a short month, all "day 29/30/31" subscriptions bill too.
     */
    private static function lastDayOfMonthDayCondition(): string
    {
        return <<<'SQL'
            DATE_PART('day', (subscriptions.subscription_at%AT%)) = ANY (
              -- Check if today is the last day of the month
              CASE WHEN DATE_PART('day', (%END_OF_MONTH%)) = DATE_PART('day', :today%AT%)
              THEN
                -- If so and if it counts less than 31 days, we need to take all days up to 31 into account
                (SELECT ARRAY(SELECT generate_series(DATE_PART('day', :today%AT%)::integer, 31)))
              ELSE
                -- Otherwise, we just need the current day
                (SELECT ARRAY[DATE_PART('day', :today%AT%)])
              END
            )
            SQL;
    }

    private static function endOfMonth(): string
    {
        return "(DATE_TRUNC('month', :today%AT%) + INTERVAL '1 month - 1 day')::date";
    }

    /**
     * NOTE: Retrieve the list of subscriptions that should be billed today.
     */
    private function billableSubscriptions(): Collection
    {
        $sql = <<<'SQL'
            WITH
              billable_subscriptions AS (
                -- Calendar subscriptions
                (%WEEKLY_CALENDAR%)
                UNION
                (%MONTHLY_CALENDAR%)
                UNION
                (%QUARTERLY_CALENDAR%)
                UNION
                (%SEMIANNUAL_WITH_MONTHLY_CHARGES_CALENDAR%)
                UNION
                (%SEMIANNUAL_WITH_MONTHLY_FIXED_CHARGES_CALENDAR%)
                UNION
                (%SEMIANNUAL_CALENDAR%)
                UNION
                (%YEARLY_WITH_MONTHLY_CHARGES_CALENDAR%)
                UNION
                (%YEARLY_WITH_MONTHLY_FIXED_CHARGES_CALENDAR%)
                UNION
                (%YEARLY_CALENDAR%)
                UNION
                -- Anniversary subscriptions
                (%WEEKLY_ANNIVERSARY%)
                UNION
                (%MONTHLY_ANNIVERSARY%)
                UNION
                (%QUARTERLY_ANNIVERSARY%)
                UNION
                (%SEMIANNUAL_WITH_MONTHLY_CHARGES_ANNIVERSARY%)
                UNION
                (%SEMIANNUAL_WITH_MONTHLY_FIXED_CHARGES_ANNIVERSARY%)
                UNION
                (%SEMIANNUAL_ANNIVERSARY%)
                UNION
                (%YEARLY_WITH_MONTHLY_CHARGES_ANNIVERSARY%)
                UNION
                (%YEARLY_WITH_MONTHLY_FIXED_CHARGES_ANNIVERSARY%)
                UNION
                (%YEARLY_ANNIVERSARY%)
              ),
              -- Filter subscriptions already billed today (in customer's applicable timezone)
              already_billed_today AS (%ALREADY_BILLED_TODAY%)

            SELECT DISTINCT(subscriptions.*)
            FROM subscriptions
              INNER JOIN billable_subscriptions ON billable_subscriptions.subscription_id = subscriptions.id
              INNER JOIN customers ON customers.id = subscriptions.customer_id
              INNER JOIN organizations ON organizations.id = customers.organization_id
              INNER JOIN billing_entities ON billing_entities.id = customers.billing_entity_id
              LEFT JOIN already_billed_today ON already_billed_today.subscription_id = subscriptions.id
            WHERE
              organizations.id = '%ORGANIZATION_ID%'

              -- Exclude subscriptions already billed today
              AND already_billed_today.invoiced_count IS NULL

              -- Do not bill subscriptions that have started _after_ :today (excludes subscriptions starting today! and also importantly invoices that might have started after this service is run)
              AND DATE(subscriptions.started_at%AT_TIME_ZONE%) < DATE(:today%AT_TIME_ZONE%)
              -- Do not bill subscriptions that were not created yet
              and DATE(subscriptions.created_at) <= Date(:today)
              AND (
                subscriptions.ending_at IS NULL OR
                DATE(subscriptions.ending_at%AT_TIME_ZONE%) != DATE(:today%AT_TIME_ZONE%)
              )
            GROUP BY subscriptions.id
            SQL;

        $replacements = [
            '%WEEKLY_CALENDAR%' => $this->weeklyCalendar(),
            '%MONTHLY_CALENDAR%' => $this->monthlyCalendar(),
            '%QUARTERLY_CALENDAR%' => $this->quarterlyCalendar(),
            '%SEMIANNUAL_WITH_MONTHLY_CHARGES_CALENDAR%' => $this->semiannualWithMonthlyChargesCalendar(),
            '%SEMIANNUAL_WITH_MONTHLY_FIXED_CHARGES_CALENDAR%' => $this->semiannualWithMonthlyFixedChargesCalendar(),
            '%SEMIANNUAL_CALENDAR%' => $this->semiannualCalendar(),
            '%YEARLY_WITH_MONTHLY_CHARGES_CALENDAR%' => $this->yearlyWithMonthlyChargesCalendar(),
            '%YEARLY_WITH_MONTHLY_FIXED_CHARGES_CALENDAR%' => $this->yearlyWithMonthlyFixedChargesCalendar(),
            '%YEARLY_CALENDAR%' => $this->yearlyCalendar(),
            '%WEEKLY_ANNIVERSARY%' => $this->weeklyAnniversary(),
            '%MONTHLY_ANNIVERSARY%' => $this->monthlyAnniversary(),
            '%QUARTERLY_ANNIVERSARY%' => $this->quarterlyAnniversary(),
            '%SEMIANNUAL_WITH_MONTHLY_CHARGES_ANNIVERSARY%' => $this->semiannualWithMonthlyChargesAnniversary(),
            '%SEMIANNUAL_WITH_MONTHLY_FIXED_CHARGES_ANNIVERSARY%' => $this->semiannualWithMonthlyFixedChargesAnniversary(),
            '%SEMIANNUAL_ANNIVERSARY%' => $this->semiannualAnniversary(),
            '%YEARLY_WITH_MONTHLY_CHARGES_ANNIVERSARY%' => $this->yearlyWithMonthlyChargesAnniversary(),
            '%YEARLY_WITH_MONTHLY_FIXED_CHARGES_ANNIVERSARY%' => $this->yearlyWithMonthlyFixedChargesAnniversary(),
            '%YEARLY_ANNIVERSARY%' => $this->yearlyAnniversary(),
            '%ALREADY_BILLED_TODAY%' => $this->alreadyBilledToday(),
            '%ORGANIZATION_ID%' => $this->organization->id,
            '%AT_TIME_ZONE%' => self::atTimeZone(),
        ];

        $sql = str_replace(array_keys($replacements), array_values($replacements), $sql);

        $rows = collect(\Illuminate\Support\Facades\DB::select($sql, ['today' => $this->today->toDateTimeString()]));

        $ids = $rows->pluck('id')->all();

        if ($ids === []) {
            return collect();
        }

        // Rails hydrates with find_by_sql; we hydrate Eloquent models for the
        // grouping step (relations are used downstream).
        return Subscription::query()->with(['plan', 'customer'])->find($ids);
    }

    // -- SQL fragments (verbatim port of the Rails scopes) ---------------------

    private function baseSubscriptionScope(string $billingTime, PlanInterval $interval, array $conditions): string
    {
        $billingTimeValue = $billingTime === 'calendar'
            ? BillingTime::Calendar->value
            : BillingTime::Anniversary->value;

        $conditionsSql = implode(' AND ', $conditions);

        return <<<SQL
            SELECT subscriptions.id AS subscription_id
            FROM subscriptions
              INNER JOIN plans ON plans.id = subscriptions.plan_id
              INNER JOIN customers ON customers.id = subscriptions.customer_id
              INNER JOIN billing_entities ON billing_entities.id = customers.billing_entity_id
              INNER JOIN organizations ON organizations.id = customers.organization_id
            WHERE subscriptions.status = {STATUS_ACTIVE}
              AND organizations.id = '{ORGANIZATION_ID_TOKEN}'
              AND subscriptions.billing_time = {BILLING_TIME}
              AND plans.interval = {INTERVAL}
              AND {$conditionsSql}
            GROUP BY subscriptions.id
            SQL;
    }

    // NOTE: For weekly interval we send invoices on Monday (ISODOW = 1)
    private function weeklyCalendar(): string
    {
        return $this->scopeCalendar(PlanInterval::Weekly, ['EXTRACT(ISODOW FROM (:today%AT%)) = 1']);
    }

    // NOTE: Billed monthly on 1st day of the month
    private function monthlyCalendar(): string
    {
        return $this->scopeCalendar(PlanInterval::Monthly, ["DATE_PART('day', (:today%AT%)) = 1"]);
    }

    // NOTE: Billed quarterly on 1st day of the January, April, July and October
    private function quarterlyCalendar(): string
    {
        return $this->scopeCalendar(PlanInterval::Quarterly, [
            "(DATE_PART('month', (:today%AT%)) IN (1, 4, 7, 10))",
            "(DATE_PART('day', (:today%AT%)) = 1)",
        ]);
    }

    // NOTE: Bill charges monthly for yearly plans on 1st day of the month
    private function yearlyWithMonthlyChargesCalendar(): string
    {
        return $this->scopeCalendar(PlanInterval::Yearly, [
            "DATE_PART('day', (:today%AT%)) = 1",
            "plans.bill_charges_monthly = 't'",
        ]);
    }

    // NOTE: Bill fixed charges monthly for yearly plans on 1st day of the month
    //       Only when charges are NOT billed monthly (otherwise the other branch handles it)
    private function yearlyWithMonthlyFixedChargesCalendar(): string
    {
        return $this->scopeCalendar(PlanInterval::Yearly, [
            "DATE_PART('day', (:today%AT%)) = 1",
            "plans.bill_fixed_charges_monthly = 't'",
            "(plans.bill_charges_monthly = 'f' OR plans.bill_charges_monthly IS NULL)",
        ]);
    }

    // NOTE: Billed yearly on first day of the year
    private function yearlyCalendar(): string
    {
        return $this->scopeCalendar(PlanInterval::Yearly, [
            "DATE_PART('month', (:today%AT%)) = 1",
            "DATE_PART('day', (:today%AT%)) = 1",
        ]);
    }

    // NOTE: Billed twice a year on 1st day of the January and July
    private function semiannualCalendar(): string
    {
        return $this->scopeCalendar(PlanInterval::Semiannual, [
            "(DATE_PART('month', (:today%AT%)) IN (1, 7))",
            "(DATE_PART('day', (:today%AT%)) = 1)",
        ]);
    }

    // NOTE: Bill charges monthly for semiannual plans on 1st day of the month
    private function semiannualWithMonthlyChargesCalendar(): string
    {
        return $this->scopeCalendar(PlanInterval::Semiannual, [
            "DATE_PART('day', (:today%AT%)) = 1",
            "plans.bill_charges_monthly = 't'",
        ]);
    }

    private function semiannualWithMonthlyFixedChargesCalendar(): string
    {
        return $this->scopeCalendar(PlanInterval::Semiannual, [
            "DATE_PART('day', (:today%AT%)) = 1",
            "plans.bill_fixed_charges_monthly = 't'",
            "(plans.bill_charges_monthly = 'f' OR plans.bill_charges_monthly IS NULL)",
        ]);
    }

    private function weeklyAnniversary(): string
    {
        return $this->scopeAnniversary(PlanInterval::Weekly, [
            'EXTRACT(ISODOW FROM (subscriptions.subscription_at%AT%)) = EXTRACT(ISODOW FROM (:today%AT%))',
        ]);
    }

    private function monthlyAnniversary(): string
    {
        return $this->scopeAnniversary(PlanInterval::Monthly, [self::lastDayOfMonthDayCondition()]);
    }

    // NOTE: Billed quarterly on anniversary date
    private function quarterlyAnniversary(): string
    {
        $billingMonth = <<<'SQL'
            (
              -- We need to avoid zero and instead of it use 12. E.g.: (3 + 9) % 12 = 0 -> 12
              CASE WHEN MOD(CAST(DATE_PART('month', (subscriptions.subscription_at%AT%)) AS INTEGER), 3) = 0
              THEN
                (DATE_PART('month', :today%AT%) IN (3, 6, 9, 12))
              ELSE (
                DATE_PART('month', (subscriptions.subscription_at%AT%)) = DATE_PART('month', :today%AT%)
                  OR MOD(CAST(DATE_PART('month', (subscriptions.subscription_at%AT%)) + 3 AS INTEGER), 12) = DATE_PART('month', :today%AT%)
                  OR MOD(CAST(DATE_PART('month', (subscriptions.subscription_at%AT%)) + 6 AS INTEGER), 12) = DATE_PART('month', :today%AT%)
                  OR MOD(CAST(DATE_PART('month', (subscriptions.subscription_at%AT%)) + 9 AS INTEGER), 12) = DATE_PART('month', :today%AT%)
              )
              END
            )
            SQL;

        return $this->scopeAnniversary(PlanInterval::Quarterly, [
            self::lastDayOfMonthDayCondition(),
            $billingMonth,
        ]);
    }

    private function yearlyAnniversary(): string
    {
        $billingMonth = "-- Ensure we are on the billing month
            DATE_PART('month', (subscriptions.subscription_at%AT%)) = DATE_PART('month', :today%AT%)";

        $billingDay = <<<'SQL'
            -- Check if we are not in a leap year when today is february the 28th
            DATE_PART('day', (subscriptions.subscription_at%AT%)) = ANY (
              CASE WHEN (
                DATE_PART('month', :today%AT%) = 2
                AND DATE_PART('day', :today%AT%) = 28
                AND DATE_PART('day', (%END_OF_MONTH%)) = 28
              )
              THEN
                -- If not a leap year, we have to take february the 29th into account
                ARRAY[28, 29]
              ELSE
                -- Otherwise, we just need the current day
                ARRAY[DATE_PART('day', :today%AT%)]
              END
            )
            SQL;

        return $this->scopeAnniversary(PlanInterval::Yearly, [$billingDay, $billingMonth]);
    }

    private function yearlyWithMonthlyChargesAnniversary(): string
    {
        return $this->scopeAnniversary(PlanInterval::Yearly, [
            "plans.bill_charges_monthly = 't'",
            self::lastDayOfMonthDayCondition(),
        ]);
    }

    private function yearlyWithMonthlyFixedChargesAnniversary(): string
    {
        return $this->scopeAnniversary(PlanInterval::Yearly, [
            "plans.bill_fixed_charges_monthly = 't'",
            "(plans.bill_charges_monthly = 'f' OR plans.bill_charges_monthly IS NULL)",
            self::lastDayOfMonthDayCondition(),
        ]);
    }

    private function semiannualAnniversary(): string
    {
        $billingMonth = <<<'SQL'
            (
              -- We need to avoid zero and instead of it use 12. E.g.: (3 + 9) % 12 = 0 -> 12
              CASE WHEN MOD(CAST(DATE_PART('month', (subscriptions.subscription_at%AT%)) AS INTEGER), 6) = 0
              THEN
                (DATE_PART('month', :today%AT%) IN (6, 12))
              ELSE (
                DATE_PART('month', (subscriptions.subscription_at%AT%)) = DATE_PART('month', :today%AT%)
                  OR MOD(CAST(DATE_PART('month', (subscriptions.subscription_at%AT%)) + 6 AS INTEGER), 12) = DATE_PART('month', :today%AT%)
              )
              END
            )
            SQL;

        return $this->scopeAnniversary(PlanInterval::Semiannual, [
            self::lastDayOfMonthDayCondition(),
            $billingMonth,
        ]);
    }

    private function semiannualWithMonthlyChargesAnniversary(): string
    {
        return $this->scopeAnniversary(PlanInterval::Semiannual, [
            "plans.bill_charges_monthly = 't'",
            self::lastDayOfMonthDayCondition(),
        ]);
    }

    private function semiannualWithMonthlyFixedChargesAnniversary(): string
    {
        return $this->scopeAnniversary(PlanInterval::Semiannual, [
            "plans.bill_fixed_charges_monthly = 't'",
            "(plans.bill_charges_monthly = 'f' OR plans.bill_charges_monthly IS NULL)",
            self::lastDayOfMonthDayCondition(),
        ]);
    }

    /** Fills the enum placeholders inside a scope fragment. */
    private function renderScope(string $scope): string
    {
        return str_replace(
            ['{STATUS_ACTIVE}', '{BILLING_TIME}', '{INTERVAL}', '{ORGANIZATION_ID_TOKEN}', '%END_OF_MONTH%', '%AT%'],
            [
                (string) SubscriptionStatus::Active->value,
                (string) $this->currentBillingTime,
                (string) $this->currentInterval,
                $this->organization->id,
                self::endOfMonth(),
                self::atTimeZone(),
            ],
            $scope,
        );
    }

    private function scopeCalendar(PlanInterval $interval, array $conditions): string
    {
        $this->currentBillingTime = BillingTime::Calendar->value;
        $this->currentInterval = $interval->value;

        return $this->renderScope($this->baseSubscriptionScope('calendar', $interval, $conditions));
    }

    private function scopeAnniversary(PlanInterval $interval, array $conditions): string
    {
        $this->currentBillingTime = BillingTime::Anniversary->value;
        $this->currentInterval = $interval->value;

        return $this->renderScope($this->baseSubscriptionScope('anniversary', $interval, $conditions));
    }

    private function alreadyBilledToday(): string
    {
        $atTimeZone = self::atTimeZone(customer: 'cus', billingEntity: 'billing_entities');

        return <<<SQL
            SELECT
              invoice_subscriptions.subscription_id,
              COUNT(invoice_subscriptions.id) AS invoiced_count
            FROM invoice_subscriptions
              INNER JOIN subscriptions AS sub ON invoice_subscriptions.subscription_id = sub.id
              INNER JOIN customers AS cus ON sub.customer_id = cus.id
              INNER JOIN billing_entities ON cus.billing_entity_id = billing_entities.id
              INNER JOIN organizations AS org ON cus.organization_id = org.id
            WHERE invoice_subscriptions.recurring = 't'
              AND org.id = '{$this->organization->id}'
              AND invoice_subscriptions.timestamp IS NOT NULL
              AND DATE(
                (invoice_subscriptions.timestamp){$atTimeZone}
              ) = DATE(:today{$atTimeZone})
            GROUP BY invoice_subscriptions.subscription_id
            SQL;
    }

    // -- PHP-side grouping (port of the group_by chain) --------------------------

    /**
     * @return list<list<Subscription>>
     */
    private function groupByPaymentMethod(array $subscriptions): array
    {
        if (count($subscriptions) <= 1) {
            return [$subscriptions];
        }

        $customer = $subscriptions[0]->customer;
        $defaultPaymentMethod = $customer->default_payment_method ?? null;

        $resolve = fn (Subscription $s) => $this->resolvePaymentMethodKey($s, $defaultPaymentMethod);

        $keys = array_unique(array_map($resolve, $subscriptions), SORT_REGULAR);

        if (count($keys) === 1) {
            return [$subscriptions];
        }

        return array_map(fn ($group) => array_values($group instanceof Collection ? $group->all() : $group), collect($subscriptions)->groupBy($resolve)->values()->all());
    }

    /**
     * NOTE: Returns the effective payment method key for grouping — an
     * explicit payment_method_id wins, nil inherits from the customer's
     * default payment method.
     */
    private function resolvePaymentMethodKey(Subscription $subscription, $defaultPaymentMethod): array
    {
        if ($subscription->payment_method_id !== null) {
            return [$subscription->payment_method_id, $subscription->payment_method_type];
        }

        if ($subscription->payment_method_type === 'manual') {
            return [null, 'manual'];
        }

        if ($defaultPaymentMethod !== null) {
            return [$defaultPaymentMethod->id, 'provider'];
        }

        return [null, $subscription->payment_method_type];
    }

    /**
     * @return list<list<Subscription>>
     */
    private function groupByCurrency(array $subscriptionGroups): array
    {
        return $this->flatGroupBy($subscriptionGroups, fn (Subscription $s) => $s->plan->amount_currency);
    }

    /**
     * NOTE: Any subscription with consolidate_invoice = false must be billed
     * on its own invoice, regardless of the other grouping criteria.
     *
     * @return list<list<Subscription>>
     */
    private function splitConsolidationOptedOut(array $subscriptionGroups): array
    {
        $groups = [];

        foreach ($subscriptionGroups as $subscriptions) {
            $optedOut = array_values(array_filter(
                $subscriptions,
                fn (Subscription $s) => ! $s->consolidate_invoice,
            ));
            $consolidated = array_values(array_filter(
                $subscriptions,
                fn (Subscription $s) => $s->consolidate_invoice,
            ));

            foreach ($optedOut as $subscription) {
                $groups[] = [$subscription];
            }

            if ($consolidated !== []) {
                $groups[] = $consolidated;
            }
        }

        return $groups;
    }

    /**
     * @return list<list<Subscription>>
     */
    private function groupByBillingEntity(array $subscriptionGroups): array
    {
        return $this->flatGroupBy(
            $subscriptionGroups,
            fn (Subscription $s) => $s->billing_entity_id ?? $s->customer?->billing_entity_id,
        );
    }

    /**
     * @return list<list<Subscription>>
     */
    private function groupByPurchaseOrderNumber(array $subscriptionGroups): array
    {
        return $this->flatGroupBy($subscriptionGroups, fn (Subscription $s) => $s->purchase_order_number);
    }

    /**
     * @param  list<list<Subscription>>  $subscriptionGroups
     * @return list<list<Subscription>>
     */
    private function flatGroupBy(array $subscriptionGroups, callable $keyBy): array
    {
        $groups = [];

        foreach ($subscriptionGroups as $subscriptions) {
            foreach (collect($subscriptions)->groupBy($keyBy)->values()->all() as $group) {
                $items = $group instanceof Collection ? $group->all() : $group;
                $groups[] = array_values($items);
            }
        }

        return $groups;
    }
}
