<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :event factory (spec/factories/events.rb): an event bound
 * to a subscription's external_id (which creates the subscription, as in
 * Rails).
 *
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'external_subscription_id' => SubscriptionFactory::new()->create()->external_id,
            'transaction_id' => 'tr_'.bin2hex(random_bytes(10)),
            'code' => $this->faker->regexify('[a-z0-9]{10}'),
            'timestamp' => now(),
        ];
    }

    /**
     * Rails: factory :received_event — the subscription (and org) are
     * created first, the event carries their ids.
     */
    public function received(): static
    {
        return $this->state(fn (array $attributes) => [
            'organization_id' => OrganizationFactory::new(),
            'external_subscription_id' => SubscriptionFactory::new()->create()->external_id,
        ]);
    }

    /** Rails: trait :discarded (Discard#discard!). */
    public function discarded(): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted_at' => now(),
        ]);
    }
}
