<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RatePhase;
use App\Models\PlanRateCard;
use App\Models\RateOverride;
use App\Models\ContractRateCard;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :rate_phase factory (spec/factories/rate_phases.rb) — one
 * phase on a plan or contract applied rate card.
 *
 * @extends Factory<RatePhase>
 */
class RatePhaseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'plan_rate_card_id' => PlanRateCardFactory::new(),
            'code' => 'default',
            'position' => 1,
            'name' => null,
            'billing_interval_cycle_count' => null,
        ];
    }

    /** Rails: `for_plan_rate_card` / `for_contract_rate_card`. */
    public function forPlanRateCard(PlanRateCard $planRateCard): static
    {
        return $this->for($planRateCard, 'planRateCard')->state([
            'contract_rate_card_id' => null,
        ]);
    }

    public function forContractRateCard(ContractRateCard $contractRateCard): static
    {
        return $this->for($contractRateCard, 'contractRateCard')->state([
            'plan_rate_card_id' => null,
        ]);
    }

    /** Rails trait `:with_override`. */
    public function withOverride(RateOverrideFactory|RateOverride|null $override = null): static
    {
        return $this->state(fn (array $attributes) => [
            'rate_override_id' => $override ?? RateOverrideFactory::new(),
        ]);
    }
}
