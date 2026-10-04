<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :product_category factory (spec/factories/product_categories.rb).
 *
 * @extends Factory<ProductCategory>
 */
class ProductCategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'code' => 'category_'.$this->faker->uuid(),
            'name' => 'Category '.$this->faker->words(2, true),
        ];
    }
}
