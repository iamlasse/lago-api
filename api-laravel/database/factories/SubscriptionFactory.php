<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Plan;
use App\Models\Customer;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :subscription factory
 * (spec/factories/subscriptions.rb).
 *
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => CustomerFactory::new(),
            'plan_id' => PlanFactory::new(),
            'organization_id' => function (array $attributes) {
                if ($attributes['customer_id'] instanceof Customer) {
                    return $attributes['customer_id']->organization_id;
                }

                if ($attributes['plan_id'] instanceof Plan) {
                    return $attributes['plan_id']->organization_id;
                }

                return OrganizationFactory::new();
            },
            'status' => 'active',
            'external_id' => $this->faker->uuid(),
            'started_at' => now()->subDay(),
            'activated_at' => now()->subDay(),
            'subscription_at' => now()->subDay(),
        ];
    }

    /** Rails trait :pending. */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'started_at' => null,
            'activated_at' => null,
        ]);
    }

    /** Rails trait :canceled. */
    public function canceled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'canceled',
            'canceled_at' => now(),
        ]);
    }

    /** Rails trait :terminated. */
    public function terminated(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'terminated',
            'started_at' => now()->subMonth(),
            'activated_at' => now()->subMonth(),
            'terminated_at' => now(),
        ]);
    }

    /** Rails trait :incomplete. */
    public function incomplete(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'incomplete',
            'started_at' => now(),
            'activated_at' => null,
        ]);
    }

    /** Rails trait :calendar. */
    public function calendar(): static
    {
        return $this->state(fn (array $attributes) => [
            'billing_time' => 'calendar',
        ]);
    }

    /** Rails trait :anniversary. */
    public function anniversary(): static
    {
        return $this->state(fn (array $attributes) => [
            'billing_time' => 'anniversary',
        ]);
    }

    /** Rails trait :with_previous_subscription. */
    public function withPreviousSubscription(): static
    {
        return $this->afterCreating(function (Subscription $subscription): void {
            $previous = Subscription::factory()->create([
                'customer_id' => $subscription->customer_id,
                'plan_id' => $subscription->plan_id,
                'organization_id' => $subscription->organization_id,
            ]);

            $subscription->previous_subscription_id = $previous->id;
            $subscription->save();
        });
    }

    /** Rails trait :with_purchase_order_number. */
    public function withPurchaseOrderNumber(): static
    {
        return $this->state(fn (array $attributes) => [
            'purchase_order_number' => 'PO-123',
        ]);
    }
}
