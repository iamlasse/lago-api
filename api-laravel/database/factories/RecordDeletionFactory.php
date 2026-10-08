<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use App\Models\RecordDeletion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :record_deletion factory (spec/factories/record_deletions.rb).
 *
 * @extends Factory<RecordDeletion>
 */
class RecordDeletionFactory extends Factory
{
    protected $model = RecordDeletion::class;

    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'record_table' => 'fees',
            'record_id' => $this->faker->uuid(),
            'deleted_at' => now(),
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn (): array => [
            'organization_id' => $organization->id,
        ]);
    }
}
