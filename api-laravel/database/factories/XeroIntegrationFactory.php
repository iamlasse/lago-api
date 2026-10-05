<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Integration;
use App\Models\Organization;
use App\Models\Integrations\XeroIntegration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :xero_integration factory (spec/factories/integrations.rb):
 * connection_id JSON-encoded in secrets.
 *
 * @extends Factory<XeroIntegration>
 */
class XeroIntegrationFactory extends Factory
{
    protected $model = XeroIntegration::class;

    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'type' => Integration::XERO_TYPE,
            'code' => 'xero',
            'name' => 'Xero Integration',
            'settings' => [
                'sync_credit_notes' => true,
                'sync_invoices' => true,
                'sync_payments' => true,
            ],
            'secrets' => json_encode([
                'connection_id' => $this->faker->uuid(),
            ]),
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->for($organization);
    }
}
