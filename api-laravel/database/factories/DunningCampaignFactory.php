<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use App\Models\DunningCampaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :dunning_campaign factory.
 *
 * @extends Factory<DunningCampaign>
 */
class DunningCampaignFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'name' => $this->faker->name(),
            'code' => 'campaign_'.$this->faker->uuid(),
            'description' => $this->faker->sentence(),
            'days_between_attempts' => 1,
            'max_attempts' => 1,
            'bcc_emails' => [],
        ];
    }

    /** Applied to the organization (the legacy organization-wide flag). */
    public function appliedToOrganization(): static
    {
        return $this->state(fn () => ['applied_to_organization' => true]);
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->for($organization, 'organization');
    }
}
