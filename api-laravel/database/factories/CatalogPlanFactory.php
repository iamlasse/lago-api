<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CatalogPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :catalog_plan factory (spec/factories/catalog_plans.rb).
 *
 * @extends Factory<CatalogPlan>
 */
class CatalogPlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'code' => 'catalog_plan_'.$this->faker->uuid(),
            'name' => 'Catalog plan '.$this->faker->words(2, true),
            'currency' => 'EUR',
        ];
    }
}
