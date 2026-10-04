<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ProductFilter;
use App\Models\ProductFilterValue;
use App\Models\BillableMetricFilter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :product_filter_value factory
 * (spec/factories/product_filter_values.rb).
 *
 * @extends Factory<ProductFilterValue>
 */
class ProductFilterValueFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'product_filter_id' => ProductFilterFactory::new(),
            'billable_metric_filter_id' => BillableMetricFilterFactory::new(),
            'value' => $this->faker->word(),
        ];
    }

    /** Rails: `for_product_filter`. */
    public function forProductFilter(ProductFilter $productFilter): static
    {
        return $this->for($productFilter, 'productFilter');
    }

    /** Rails: `for_billable_metric_filter`. */
    public function forBillableMetricFilter(BillableMetricFilter $billableMetricFilter): static
    {
        return $this->for($billableMetricFilter, 'billableMetricFilter');
    }
}
