<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\RateCard;
use App\Models\PricingUnit;
use Carbon\CarbonImmutable;
use App\Models\Organization;
use App\Models\RateCardRate;
use App\Models\RateOverride;
use App\Models\ContractRateCard;
use Illuminate\Database\QueryException;
use App\Services\Billing\BillableSegment;
use App\Services\BillingSegments\CreateService;

/**
 * Port of spec/services/billing_segments/create_service_spec.rb.
 */
function createSpecFixture(string $currency = 'USD'): array
{
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $contract = Contract::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
    ]);
    $product = Product::factory()->create(['organization_id' => $organization->id]);
    $rateCard = RateCard::factory()->create([
        'organization_id' => $organization->id,
        'product_id' => $product->id,
        'currency' => $currency,
    ]);
    $contractRateCard = ContractRateCard::factory()->create([
        'organization_id' => $organization->id,
        'contract_id' => $contract->id,
        'rate_card_id' => $rateCard->id,
    ]);
    $rate = RateCardRate::factory()->create([
        'organization_id' => $organization->id,
        'rate_card_id' => $rateCard->id,
        'rate_properties' => ['amount' => '12'],
    ]);

    return compact('organization', 'customer', 'contract', 'product', 'rateCard', 'contractRateCard', 'rate');
}

function createSpecBillable(RateCardRate $rate, ?RateOverride $rateOverride = null): BillableSegment
{
    $cycleStartedAt = CarbonImmutable::parse('2026-02-01 00:00:00', 'UTC');
    $exclusiveEnd = CarbonImmutable::parse('2026-03-01 00:00:00', 'UTC');

    return new BillableSegment(
        cycleIndex: 0,
        cycleStartedAt: $cycleStartedAt,
        startedAt: CarbonImmutable::parse('2026-02-15 00:00:00', 'UTC'),
        endedAt: $exclusiveEnd,
        billingAt: $exclusiveEnd,
        rate: $rate,
        rateOverride: $rateOverride,
        prorationRatio: 0.5,
        ratePhaseCode: null,
    );
}

it('stores a metered arrears slice as a pending segment', function (): void {
    extract(createSpecFixture());

    $result = CreateService::call(
        contractRateCard: $contractRateCard,
        billableSegments: [createSpecBillable($rate)],
        pricingUnit: null,
    );

    $segment = $result->billing_segments[0];

    expect($segment->organization_id)->toBe($organization->id)
        ->and($segment->contract_id)->toBe($contract->id)
        ->and($segment->customer_id)->toBe($customer->id)
        ->and($segment->contract_rate_card_id)->toBe($contractRateCard->id)
        ->and($segment->cycle_started_at->equalTo(CarbonImmutable::parse('2026-02-01 00:00:00', 'UTC')))->toBeTrue()
        ->and($segment->started_at->toDateString())->toBe('2026-02-15')
        ->and($segment->billing_at->equalTo(CarbonImmutable::parse('2026-03-01 00:00:00', 'UTC')))->toBeTrue()
        ->and($segment->rate_card_rate_id)->toBe($rate->id)
        ->and($segment->rate_override_id)->toBeNull()
        ->and($segment->rate_properties)->toBe(['amount' => '12'])
        ->and($segment->currency)->toBe('USD')
        ->and($segment->pricing_unit_id)->toBeNull()
        ->and((float) $segment->proration_ratio)->toBe(0.5)
        ->and($segment->status->value)->toBe('pending');
});

it('stores a metered advance slice as a processing segment', function (): void {
    extract(createSpecFixture());
    $rateCard->update(['billing_timing' => 'advance']);

    $result = CreateService::call(
        contractRateCard: $contractRateCard,
        billableSegments: [createSpecBillable($rate)],
        pricingUnit: null,
    );

    expect($result->billing_segments[0]->status->value)->toBe('processing');
});

it('stores a fixed advance slice as a pending segment', function (): void {
    extract(createSpecFixture());
    $product->update(['product_type' => 'fixed']);
    $rateCard->update(['billing_timing' => 'advance']);

    $result = CreateService::call(
        contractRateCard: $contractRateCard,
        billableSegments: [createSpecBillable($rate)],
        pricingUnit: null,
    );

    expect($result->billing_segments[0]->status->value)->toBe('pending');
});

it('takes its owners from the card own contract not from a sibling', function (): void {
    extract(createSpecFixture());
    $otherCustomer = Customer::factory()->create(['organization_id' => $organization->id]);
    Contract::factory()->create(['organization_id' => $organization->id, 'customer_id' => $otherCustomer->id]);

    $result = CreateService::call(
        contractRateCard: $contractRateCard,
        billableSegments: [createSpecBillable($rate)],
        pricingUnit: null,
    );

    expect($result->billing_segments[0]->customer_id)->toBe($customer->id)
        ->and($result->billing_segments[0]->contract_id)->toBe($contract->id);
});

it('converts the calendar exclusive end to the stored inclusive end', function (): void {
    extract(createSpecFixture());

    $result = CreateService::call(
        contractRateCard: $contractRateCard,
        billableSegments: [createSpecBillable($rate)],
        pricingUnit: null,
    );

    expect($result->billing_segments[0]->ended_at->equalTo(CarbonImmutable::parse('2026-02-28 23:59:59.999999', 'UTC')))->toBeTrue();
});

it('stores the pricing unit the caller resolved', function (): void {
    extract(createSpecFixture());
    $pricingUnit = PricingUnit::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Debug Credits',
        'code' => 'DBG',
        'short_name' => 'DBG',
    ]);

    $result = CreateService::call(
        contractRateCard: $contractRateCard,
        billableSegments: [createSpecBillable($rate)],
        pricingUnit: $pricingUnit,
    );

    expect($result->billing_segments[0]->pricing_unit_id)->toBe($pricingUnit->id);
});

it('snapshots the phase override properties over the rate', function (): void {
    extract(createSpecFixture());
    $rateOverride = RateOverride::factory()->create([
        'organization_id' => $organization->id,
        'rate_properties' => ['amount' => '7'],
    ]);

    $result = CreateService::call(
        contractRateCard: $contractRateCard,
        billableSegments: [createSpecBillable($rate, $rateOverride)],
        pricingUnit: null,
    );

    expect($result->billing_segments[0]->rate_card_rate_id)->toBe($rate->id)
        ->and($result->billing_segments[0]->rate_override_id)->toBe($rateOverride->id)
        ->and($result->billing_segments[0]->rate_properties)->toBe(['amount' => '7']);
});

// The overlap EXCLUDE constraint is the last-resort guard behind
// MissingBillableSegmentsService's subtraction (Rails insert_all! raises too).
it('stays loud when a duplicate segment reaches the database', function (): void {
    extract(createSpecFixture());
    $billable = createSpecBillable($rate);

    CreateService::call(
        contractRateCard: $contractRateCard,
        billableSegments: [$billable],
        pricingUnit: null,
    );

    $dupe = new BillableSegment(
        cycleIndex: 0,
        cycleStartedAt: $billable->cycleStartedAt,
        startedAt: $billable->startedAt,
        endedAt: $billable->endedAt,
        billingAt: $billable->billingAt,
        rate: $rate,
        rateOverride: null,
        prorationRatio: 1.0,
        ratePhaseCode: null,
    );

    CreateService::call(
        contractRateCard: $contractRateCard,
        billableSegments: [$dupe],
        pricingUnit: null,
    );
})->throws(QueryException::class);
