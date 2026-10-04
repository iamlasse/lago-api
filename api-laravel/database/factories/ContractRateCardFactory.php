<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contract;
use App\Models\RateCard;
use App\Models\ContractRateCard;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :contract_rate_card factory
 * (spec/factories/contract_rate_cards.rb) — a contract's applied rate card.
 *
 * @extends Factory<ContractRateCard>
 */
class ContractRateCardFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'contract_id' => ContractFactory::new(),
            'rate_card_id' => RateCardFactory::new(),
            'billing_anchor_date' => now()->startOfDay(),
            'effective_date' => now()->startOfDay(),
            'next_billing_at' => now()->addMonth()->startOfDay(),
            'units' => null,
        ];
    }

    /** Rails: `for_contract`. */
    public function forContract(Contract $contract): static
    {
        return $this->for($contract, 'contract');
    }

    /** Rails: `for_rate_card`. */
    public function forRateCard(RateCard $rateCard): static
    {
        return $this->for($rateCard, 'rateCard');
    }
}
