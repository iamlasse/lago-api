<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tax;
use App\Models\RateCard;
use App\Models\RateCardAppliedTax;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :rate_card_applied_tax factory
 * (spec/factories/rate_card_applied_taxes.rb) — the rate_cards_taxes join.
 *
 * @extends Factory<RateCardAppliedTax>
 */
class RateCardAppliedTaxFactory extends Factory
{
    protected $model = RateCardAppliedTax::class;

    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'rate_card_id' => RateCardFactory::new(),
            'tax_id' => TaxFactory::new(),
        ];
    }

    /** Rails: `for_rate_card`. */
    public function forRateCard(RateCard $rateCard): static
    {
        return $this->for($rateCard, 'rateCard');
    }

    /** Rails: `for_tax`. */
    public function forTax(Tax $tax): static
    {
        return $this->for($tax, 'tax');
    }
}
