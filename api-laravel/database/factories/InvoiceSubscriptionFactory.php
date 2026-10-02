<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Subscription;
use App\Models\InvoiceSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :invoice_subscription factory (spec/factories).
 *
 * @extends Factory<InvoiceSubscription>
 */
class InvoiceSubscriptionFactory extends Factory
{
    protected $model = InvoiceSubscription::class;

    public function definition(): array
    {
        return [
            'invoice_id' => InvoiceFactory::new(),
            'subscription_id' => SubscriptionFactory::new(),
            'organization_id' => function (array $attributes): string {
                $subscription = Subscription::query()->find($attributes['subscription_id']);

                return $subscription->organization_id;
            },
            'recurring' => true,
            'timestamp' => now('UTC'),
            'from_datetime' => now('UTC')->startOfMonth(),
            'to_datetime' => now('UTC')->endOfMonth(),
            'charges_from_datetime' => now('UTC')->startOfMonth(),
            'charges_to_datetime' => now('UTC')->endOfMonth(),
            'invoicing_reason' => 'subscription_periodic',
        ];
    }
}
