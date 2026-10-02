<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use App\Models\BillingEntity;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :organization factory — an organization comes with an API
 * key, a default billing entity and a webhook endpoint.
 *
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(function (Organization $organization): void {
            if ($organization->billingEntities()->doesntExist()) {
                BillingEntity::factory()->for($organization)->create();
            }

            if ($organization->apiKeys()->doesntExist()) {
                $organization->apiKeys()->create(['name' => 'API Key']);
            }

            if ($organization->webhookEndpoints()->doesntExist()) {
                $organization->webhookEndpoints()->create([
                    'webhook_url' => $this->faker->url(),
                ]);
            }
        });
    }

    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'default_currency' => 'USD',
            'audit_logs_period' => null,
            'email' => $this->faker->email(),
            'email_settings' => ['invoice.finalized', 'credit_note.created'],
        ];
    }

    /** Rails trait :with_static_values (applies to the billing entity too). */
    public function withStaticValues(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'ACME Corporation',
            'slug' => 'acme-corp',
            'default_currency' => 'USD',
            'country' => 'US',
        ])->afterCreating(function (Organization $organization): void {
            $organization->defaultBillingEntity()->first()?->update([
                'name' => 'ACME Corporation',
                'email' => 'billing@acme.com',
                'address_line1' => '123 Business St',
                'address_line2' => 'Suite 100',
                'city' => 'San Francisco',
                'state' => 'CA',
                'zipcode' => '94105',
                'country' => 'US',
                'document_number_prefix' => 'ACM-8924',
            ]);
        });
    }

    /** Rails: `premium_integrations { Organization::PREMIUM_INTEGRATIONS }`. */
    public function premium(): static
    {
        return $this->state(fn (array $attributes) => [
            'premium_integrations' => [
                'revenue_share', 'auto_dunning', 'progressive_billing', 'lifetime_usage',
            ],
        ]);
    }

    /**
     * Skip the default billing entity creation — used when the parent
     * factory manages billing entities itself (Rails:
     * `association(:organization, billing_entities: [])`).
     */
    public function withoutBillingEntity(): static
    {
        return $this->afterMaking(function (Organization $organization): void {
            $organization->billingEntities()->delete();
        })->afterCreating(function (Organization $organization): void {
            $organization->allBillingEntities()->delete();
        });
    }

    /** Skip the default API key creation (Rails: `api_keys: []`). */
    public function withoutApiKey(): static
    {
        return $this->afterCreating(function (Organization $organization): void {
            $organization->apiKeys()->delete();
        });
    }

    /** Skip the default webhook endpoint creation. */
    public function withoutWebhookEndpoint(): static
    {
        return $this->afterCreating(function (Organization $organization): void {
            WebhookEndpoint::query()->where('organization_id', $organization->id)->delete();
        });
    }
}
