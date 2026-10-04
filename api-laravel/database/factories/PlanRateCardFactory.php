<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RateCard;
use App\Models\CatalogPlan;
use App\Models\PlanRateCard;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :plan_rate_card factory (spec/factories/plan_rate_cards.rb)
 * — a catalog plan's applied rate card.
 *
 * @extends Factory<PlanRateCard>
 */
class PlanRateCardFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'catalog_plan_id' => CatalogPlanFactory::new(),
            'rate_card_id' => RateCardFactory::new(),
            'units' => null,
        ];
    }

    /** Rails: `for_catalog_plan`. */
    public function forCatalogPlan(CatalogPlan $catalogPlan): static
    {
        return $this->for($catalogPlan, 'catalogPlan');
    }

    /** Rails: `for_rate_card`. */
    public function forRateCard(RateCard $rateCard): static
    {
        return $this->for($rateCard, 'rateCard');
    }
}
