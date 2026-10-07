<?php

declare(strict_types=1);

namespace App\Services\Subscriptions;

use App\Models\Subscription;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Carbon;

/**
 * Port of Rails' Subscriptions::ActivateAllPendingService
 * (app/services/subscriptions/activate_all_pending_service.rb): activates
 * every PENDING first-generation subscription whose subscription_at day (in
 * the customer's timezone) has arrived. Downgrade placeholders
 * (previous_subscription set) are activated by the biller instead.
 */
class ActivateAllPendingService extends BaseService
{
    protected Carbon $timestamp;

    public function __construct(int $timestamp)
    {
        parent::__construct();

        $this->timestamp = Carbon::createFromTimestampUTC($timestamp);
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult();

        $atTimeZone = $this->atTimeZone();

        // Rails: Subscription.joins(customer: :billing_entity).pending
        //   .where(previous_subscription: nil).where("DATE(...) <= DATE(?)", ...).
        $subscriptions = Subscription::query()
            ->join('customers', 'subscriptions.customer_id', '=', 'customers.id')
            ->join('billing_entities', 'customers.billing_entity_id', '=', 'billing_entities.id')
            ->pending()
            ->whereNull('subscriptions.previous_subscription_id')
            ->whereRaw(
                "DATE(subscriptions.subscription_at{$atTimeZone}) <= DATE(?{$atTimeZone})",
                [$this->timestamp->toDateTimeString()],
            )
            ->get();

        /** @var Subscription $subscription */
        foreach ($subscriptions as $subscription) {
            ActivateService::callBang(
                subscription: $subscription,
                timestamp: $this->timestamp,
            );
        }

        return $result;
    }

    /** Rails: Utils::Timezone.at_time_zone_sql (see FreeTrialBillingService). */
    protected function atTimeZone(string $customer = 'customers', string $billingEntity = 'billing_entities'): string
    {
        return "::timestamptz AT TIME ZONE COALESCE({$customer}.timezone, {$billingEntity}.timezone, 'UTC')";
    }
}
