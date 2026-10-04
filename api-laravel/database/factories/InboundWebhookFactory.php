<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\InboundWebhook;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for `inbound_webhooks` (Rails' :inbound_webhook factory).
 *
 * @extends Factory<InboundWebhook>
 */
class InboundWebhookFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'source' => 'stripe',
            'event_type' => 'payment_intent.succeeded',
            'payload' => '{}',
            'status' => 'pending',
            'signature' => null,
        ];
    }

    public function stripe(): static
    {
        return $this->state(fn () => ['source' => 'stripe']);
    }

    public function forOrganization(\App\Models\Organization $organization): static
    {
        return $this->for($organization, 'organization');
    }
}
