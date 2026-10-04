<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Enums\ProductType;
use App\Models\BillableMetric;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :product factory (spec/factories/products.rb).
 *
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'product_type' => ProductType::Metered->value,
            'code' => 'product_'.$this->faker->uuid(),
            'name' => 'Product '.$this->faker->words(2, true),
            'billable_metric_id' => BillableMetricFactory::new(),
        ];
    }

    /** Rails trait :fixed — a fixed product carries no billable metric. */
    public function fixed(): static
    {
        return $this->state(fn (array $attributes) => [
            'product_type' => ProductType::Fixed->value,
            'billable_metric_id' => null,
        ]);
    }

    /** Rails: a product in a category. */
    public function inCategory(?ProductCategoryFactory $category = null): static
    {
        return $this->state(fn (array $attributes) => [
            'product_category_id' => $category ?? ProductCategoryFactory::new(),
        ]);
    }

    /** Rails: `product_category` association on create. */
    public function forCategory($category): static
    {
        return $this->for($category, 'productCategory');
    }

    /** Rails: `for_billable_metric` — explicit metric on a metered product. */
    public function forBillableMetric(BillableMetric $billableMetric): static
    {
        return $this->for($billableMetric, 'billableMetric');
    }
}
