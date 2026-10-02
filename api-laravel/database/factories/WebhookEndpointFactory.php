<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :webhook_endpoint factory
 * (spec/factories/webhook_endpoints.rb).
 *
 * @extends Factory<WebhookEndpoint>
 */
class WebhookEndpointFactory extends Factory
{
    protected $model = WebhookEndpoint::class;

    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'webhook_url' => $this->faker->url(),
        ];
    }

    /** A reachable-by-nothing but well-formed webhook URL, like the specs'. */
    public function withUrl(string $url): static
    {
        return $this->state(fn () => ['webhook_url' => $url]);
    }

    /** Rails: signature_algo :hmac. */
    public function hmac(): static
    {
        return $this->state(fn () => ['signature_algo' => 'hmac']);
    }

    /** Rails: signature_algo :jwt (column default). */
    public function jwt(): static
    {
        return $this->state(fn () => ['signature_algo' => 'jwt']);
    }

    /** Rails: event_types list filter. */
    public function eventTypes(array $types): static
    {
        return $this->state(fn () => ['event_types' => $types]);
    }

    /** Ensure the endpoint belongs to a specific organization. */
    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn () => ['organization_id' => $organization->id]);
    }
}
