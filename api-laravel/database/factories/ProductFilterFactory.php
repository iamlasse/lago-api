<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductFilter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :product_filter factory (spec/factories/product_filters.rb).
 *
 * @extends Factory<ProductFilter>
 */
class ProductFilterFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'product_id' => ProductFactory::new(),
            'code' => 'filter_'.$this->faker->uuid(),
            'name' => 'Filter '.$this->faker->words(2, true),
        ];
    }

    /** Rails: `for_product`. */
    public function forProduct(Product $product): static
    {
        return $this->for($product, 'product');
    }
}
