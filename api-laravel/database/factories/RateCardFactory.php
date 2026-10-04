<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RateCard;
use App\Models\ProductFilter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :rate_card factory (spec/factories/rate_cards.rb).
 *
 * @extends Factory<RateCard>
 */
class RateCardFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'product_id' => ProductFactory::new(),
            'code' => 'rate_card_'.$this->faker->uuid(),
            'name' => 'Rate card '.$this->faker->words(2, true),
            'currency' => 'EUR',
            'billing_timing' => 'arrears',
            'proration' => false,
            'display_on_invoice' => true,
        ];
    }

    /** Rails: `for_product`. */
    public function forProduct(Product $product): static
    {
        return $this->for($product, 'product');
    }

    /** Rails: `scoped_to_filter` — a card on one filter slice of the item. */
    public function scopedToFilter(ProductFilter $productFilter): static
    {
        return $this->for($productFilter, 'productFilter');
    }

    /** Rails trait `billing_timing: :advance`. */
    public function billingInAdvance(): static
    {
        return $this->state(['billing_timing' => 'advance']);
    }
}
