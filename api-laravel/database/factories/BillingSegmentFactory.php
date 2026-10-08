<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\RateCardRate;
use App\Models\RateOverride;
use App\Models\BillingSegment;
use App\Models\ContractRateCard;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :billing_segment factory (spec/factories/
 * billing_segments.rb) — a durable priced slice of a contract rate card
 * billing cycle.
 *
 * @extends Factory<BillingSegment>
 */
class BillingSegmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'customer_id' => CustomerFactory::new(),
            'contract_id' => ContractFactory::new(),
            'contract_rate_card_id' => ContractRateCardFactory::new(),
            'rate_card_rate_id' => RateCardRateFactory::new(),
            'rate_override_id' => null,
            'pricing_unit_id' => null,
            'invoice_id' => null,
            'rate_properties' => '{}',
            'currency' => 'EUR',
            'billing_at' => now(),
            'cycle_started_at' => now()->startOfDay(),
            'started_at' => fn (array $attributes) => $attributes['cycle_started_at'],
            'ended_at' => fn (array $attributes) => BillingSegment::inclusiveEnd(
                \Carbon\CarbonImmutable::parse($attributes['cycle_started_at'])->addMonth(),
            ),
            'status' => 'pending',
        ];
    }

    /** Rails: `customer { association(:customer, organization:) }` etc. — owners derived together. */
    public function forContractRateCard(ContractRateCard $contractRateCard): static
    {
        return $this->for($contractRateCard, 'contractRateCard')
            ->for($contractRateCard->contract, 'contract')
            ->for($contractRateCard->contract->customer()->withTrashed()->firstOrFail(), 'customer')
            ->state([
                'organization_id' => $contractRateCard->organization_id,
                'currency' => $contractRateCard->rateCard->currency ?? 'EUR',
            ]);
    }

    /** Rails: `rate_override { nil }` — default state is the rate's. */
    public function forRate(RateCardRate $rate): static
    {
        return $this->for($rate, 'rateCardRate')
            ->state([
                'rate_override_id' => null,
                'rate_properties' => $rate->properties(),
                'currency' => $rate->rateCard->currency,
            ]);
    }

    /** Rails: `rate_override` — a phase override prices the slice instead. */
    public function forRateOverride(RateOverride $rateOverride): static
    {
        return $this->state([
            'rate_override_id' => $rateOverride->id,
            'rate_properties' => $rateOverride->properties(),
        ]);
    }

    /** Rails: `status { :pending }` — string statuses map straight through. */
    public function status(string $status): static
    {
        return $this->state(['status' => $status]);
    }
}
