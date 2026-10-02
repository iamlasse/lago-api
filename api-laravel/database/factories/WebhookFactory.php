<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WebhookStatus;
use App\Models\Organization;
use App\Models\Webhook;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :webhook factory (spec/factories/webhooks.rb).
 *
 * @extends Factory<Webhook>
 */
class WebhookFactory extends Factory
{
    protected $model = Webhook::class;

    public function definition(): array
    {
        return [
            'webhook_endpoint_id' => WebhookEndpointFactory::new(),
            'organization_id' => function (array $attributes) {
                $endpointId = $attributes['webhook_endpoint_id'] ?? null;

                if ($endpointId instanceof WebhookEndpoint) {
                    return $endpointId->organization_id;
                }

                if (is_string($endpointId)) {
                    $endpoint = WebhookEndpoint::find($endpointId);
                    if ($endpoint !== null) {
                        return $endpoint->organization_id;
                    }
                }

                return OrganizationFactory::new();
            },
            'payload' => [
                'webhook_type' => 'invoice.created',
                'object_type' => 'invoice',
                'organization_id' => $this->faker->uuid(),
                'invoice' => ['lago_id' => $this->faker->uuid()],
            ],
            'webhook_type' => 'invoice.created',
            'endpoint' => $this->faker->url(),
            'status' => WebhookStatus::Pending->value,
            'retries' => 0,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Webhook $webhook): void {
            // Keep the endpoint column in sync with the endpoint's URL, like
            // the Rails factory's endpoint attribute on a fresh endpoint.
            $webhook->endpoint ??= $webhook->webhookEndpoint?->webhook_url;
        });
    }

    /** Rails trait :succeeded. */
    public function succeeded(): static
    {
        return $this->state(fn () => [
            'http_status' => 200,
            'status' => WebhookStatus::Succeeded->value,
            'retries' => 0,
        ]);
    }

    /** Rails trait :retrying. */
    public function retrying(): static
    {
        return $this->state(fn () => [
            'status' => WebhookStatus::Retrying->value,
            'response' => json_encode([$this->faker->word() => $this->faker->word()]),
        ]);
    }

    /** Rails trait :failed. */
    public function failed(): static
    {
        return $this->state(fn () => [
            'http_status' => 500,
            'status' => WebhookStatus::Failed->value,
            'response' => json_encode([$this->faker->word() => $this->faker->word()]),
        ]);
    }

    /** Rails trait :pending. */
    public function pending(): static
    {
        return $this->state(fn () => ['status' => WebhookStatus::Pending->value]);
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn () => ['organization_id' => $organization->id]);
    }
}
