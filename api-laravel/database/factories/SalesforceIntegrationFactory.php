<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Integration;
use App\Models\Organization;
use App\Models\Integrations\SalesforceIntegration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :salesforce_integration factory (spec/factories/
 * integrations.rb): the instance id in settings.
 *
 * @extends Factory<SalesforceIntegration>
 */
class SalesforceIntegrationFactory extends Factory
{
    protected $model = SalesforceIntegration::class;

    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'type' => Integration::SALESFORCE_TYPE,
            'code' => 'salesforce',
            'name' => 'Salesforce Integration',
            'settings' => [
                'instance_id' => $this->faker->uuid(),
            ],
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->for($organization);
    }
}
