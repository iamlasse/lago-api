<?php

declare(strict_types=1);

namespace Database\Factories\Subscription;

use App\Models\Subscription;
use Database\Factories\SubscriptionFactory;
use App\Models\Subscription\ActivationRule\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :subscription_activation_rule factory
 * (spec/factories/subscription/activation_rules.rb).
 *
 * @extends Factory<Payment>
 */
class ActivationRuleFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'subscription_id' => SubscriptionFactory::new(),
            'organization_id' => function (array $attributes) {
                if ($attributes['subscription_id'] instanceof Subscription) {
                    return $attributes['subscription_id']->organization_id;
                }

                if ($attributes['subscription_id'] !== null && is_string($attributes['subscription_id'])) {
                    $subscription = Subscription::query()->find($attributes['subscription_id']);

                    if ($subscription !== null) {
                        return $subscription->organization_id;
                    }
                }

                return OrganizationFactory::new();
            },
            'type' => 'payment',
            'status' => 'inactive',
            'timeout_hours' => 48,
        ];
    }
}
