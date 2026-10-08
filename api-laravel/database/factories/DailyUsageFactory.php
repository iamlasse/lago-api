<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use App\Models\DailyUsage;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :daily_usage factory (spec/factories/daily_usages.rb).
 *
 * @extends Factory<DailyUsage>
 */
class DailyUsageFactory extends Factory
{
    protected $model = DailyUsage::class;

    public function definition(): array
    {
        return [
            'customer_id' => CustomerFactory::new(),
            'organization_id' => function (array $attributes) {
                if ($attributes['customer_id'] instanceof Customer) {
                    return $attributes['customer_id']->organization_id;
                }

                return OrganizationFactory::new();
            },
            'subscription_id' => function (array $attributes) {
                $customer = $attributes['customer_id'] instanceof Customer
                    ? $attributes['customer_id']
                    : Customer::find($attributes['customer_id']);

                return SubscriptionFactory::new()->forCustomer($customer);
            },
            'external_subscription_id' => function (array $attributes) {
                $subscription = $attributes['subscription_id'] instanceof Subscription
                    ? $attributes['subscription_id']
                    : Subscription::find($attributes['subscription_id']);

                return $subscription->external_id;
            },
            'from_datetime' => now()->startOfMonth(),
            'to_datetime' => now()->endOfMonth(),
            'refreshed_at' => now(),
            'usage' => [],
            'usage_diff' => [],
            'usage_date' => now()->subDay()->toDateString(),
        ];
    }

    /** Pin the subscription (and its customer/organization/external_id). */
    public function forSubscription(Subscription $subscription): static
    {
        return $this->state(fn (): array => [
            'subscription_id' => $subscription->id,
            'customer_id' => $subscription->customer_id,
            'organization_id' => $subscription->organization_id,
            'external_subscription_id' => $subscription->external_id,
        ]);
    }

    /** Pin the customer (and its organization). */
    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn (): array => [
            'customer_id' => $customer->id,
            'organization_id' => $customer->organization_id,
        ]);
    }
}
