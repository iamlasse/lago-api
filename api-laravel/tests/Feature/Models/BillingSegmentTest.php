<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use App\Models\RateCardRate;
use App\Models\BillingSegment;

/**
 * Port of spec/models/billing_segment_spec.rb (key cases) + the ElapsedPeriodRatio
 * spec (spec/models/billing/elapsed_period_ratio_spec.rb).
 */
function modelFixtureSegment(): BillingSegment
{
    $organization = App\Models\Organization::factory()->create();
    $customer = App\Models\Customer::factory()->create(['organization_id' => $organization->id]);
    $contract = App\Models\Contract::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
    ]);
    $rateCard = App\Models\RateCard::factory()->create(['organization_id' => $organization->id]);
    $contractRateCard = App\Models\ContractRateCard::factory()->create([
        'organization_id' => $organization->id,
        'contract_id' => $contract->id,
        'rate_card_id' => $rateCard->id,
    ]);
    $rate = RateCardRate::factory()->create([
        'organization_id' => $organization->id,
        'rate_card_id' => $rateCard->id,
        'min_amount_cents' => 10_000,
    ]);

    return BillingSegment::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'contract_id' => $contract->id,
        'contract_rate_card_id' => $contractRateCard->id,
        'rate_card_rate_id' => $rate->id,
        'currency' => 'EUR',
        'cycle_started_at' => CarbonImmutable::parse('2026-08-01 00:00:00', 'UTC'),
        'started_at' => CarbonImmutable::parse('2026-08-01 00:00:00', 'UTC'),
        'ended_at' => BillingSegment::inclusiveEnd(CarbonImmutable::parse('2026-08-20 00:00:00', 'UTC')),
        'billing_at' => CarbonImmutable::parse('2026-08-20 00:00:00', 'UTC'),
        'proration_ratio' => '0.5',
    ]);
}

it('converts between inclusive and exclusive window ends', function (): void {
    $boundary = CarbonImmutable::parse('2026-02-01 00:00:00', 'UTC');

    expect(BillingSegment::inclusiveEnd($boundary)->format('Y-m-d H:i:s.u'))->toBe('2026-01-31 23:59:59.999999')
        ->and(BillingSegment::exclusiveEnd(BillingSegment::inclusiveEnd($boundary))->equalTo($boundary))->toBeTrue();
});

it('validates presence rate presence and bounds', function (): void {
    $segment = modelFixtureSegment();

    // A valid fixture has no errors.
    expect($segment->validateAttributes())->toBe([]);

    // Rate presence: exactly one of rate_card_rate / rate_override required.
    $noRate = BillingSegment::factory()->make([
        'organization_id' => $segment->organization_id,
        'customer_id' => $segment->customer_id,
        'contract_id' => $segment->contract_id,
        'contract_rate_card_id' => $segment->contract_rate_card_id,
        'rate_card_rate_id' => null,
        'rate_override_id' => null,
        'currency' => 'EUR',
    ]);

    expect($noRate->validateAttributes()['base'] ?? [])->toContain('rate_card_rate_or_rate_override_required');

    // Period bounds: started_at must precede ended_at.
    $inverted = BillingSegment::factory()->make([
        'organization_id' => $segment->organization_id,
        'customer_id' => $segment->customer_id,
        'contract_id' => $segment->contract_id,
        'contract_rate_card_id' => $segment->contract_rate_card_id,
        'rate_card_rate_id' => $segment->rate_card_rate_id,
        'currency' => 'EUR',
        'started_at' => CarbonImmutable::parse('2026-08-10 00:00:00', 'UTC'),
        'ended_at' => CarbonImmutable::parse('2026-08-01 00:00:00', 'UTC'),
        'cycle_started_at' => CarbonImmutable::parse('2026-08-01 00:00:00', 'UTC'),
        'billing_at' => now(),
    ]);

    expect($inverted->validateAttributes()['ended_at'] ?? [])->toContain('must_be_after_started_at');

    // Cycle bounds: cycle_started_at must precede started_at.
    $cycleAfter = BillingSegment::factory()->make([
        'organization_id' => $segment->organization_id,
        'customer_id' => $segment->customer_id,
        'contract_id' => $segment->contract_id,
        'contract_rate_card_id' => $segment->contract_rate_card_id,
        'rate_card_rate_id' => $segment->rate_card_rate_id,
        'currency' => 'EUR',
        'started_at' => CarbonImmutable::parse('2026-08-01 00:00:00', 'UTC'),
        'ended_at' => CarbonImmutable::parse('2026-08-20 00:00:00', 'UTC'),
        'cycle_started_at' => CarbonImmutable::parse('2026-08-05 00:00:00', 'UTC'),
        'billing_at' => now(),
    ]);

    expect($cycleAfter->validateAttributes()['cycle_started_at'] ?? [])->toContain('must_be_before_started_at');

    // Currency inclusion.
    $badCurrency = BillingSegment::factory()->make([
        'organization_id' => $segment->organization_id,
        'customer_id' => $segment->customer_id,
        'contract_id' => $segment->contract_id,
        'contract_rate_card_id' => $segment->contract_rate_card_id,
        'rate_card_rate_id' => $segment->rate_card_rate_id,
        'currency' => 'FOO',
        'started_at' => CarbonImmutable::parse('2026-08-01 00:00:00', 'UTC'),
        'ended_at' => CarbonImmutable::parse('2026-08-20 00:00:00', 'UTC'),
        'cycle_started_at' => CarbonImmutable::parse('2026-08-01 00:00:00', 'UTC'),
        'billing_at' => now(),
    ]);

    expect($badCurrency->validateAttributes()['currency'] ?? [])->toBe(['invalid_currency']);
});

it('computes the domain helpers over the customer timezone', function (): void {
    $segment = modelFixtureSegment();

    // duration: Aug 1 -> Aug 20 inclusive-of-opening, UTC = 19 days.
    expect($segment->durationInDays())->toBe(19);

    // Rate falls back through the override-else-rate chain.
    expect($segment->rate()->id)->toBe($segment->rate_card_rate_id)
        ->and($segment->minAmountCents())->toBe(10_000);

    // Prorated minimum: 10_000 * 0.5 = 5000 fiat cents (no pricing unit).
    expect((float) $segment->proratedMinAmountCents())->toEqualWithDelta(5000.0, 1e-9);

    // Target key mirrors Rails "contract-<id>-product-<id>".
    expect($segment->targetKey())->toBe(
        "contract-{$segment->contract_id}-product-{$segment->contractRateCard->rateCard->product_id}",
    );
});

it('selects only awaiting segments of non-advance metered exclusion', function (): void {
    $segment = modelFixtureSegment();

    expect(BillingSegment::query()->awaitingInvoicing()->where('billing_segments.customer_id', $segment->customer_id)->count())->toBe(1);

    // done drops out of the awaiting set.
    $segment->update(['status' => 'done']);
    expect(BillingSegment::query()->awaitingInvoicing()->count())->toBe(0);

    // A metered-advance card's segment never enters the awaiting set.
    $meteredCard = App\Models\RateCard::factory()->create([
        'organization_id' => $segment->organization_id,
        'product_id' => App\Models\Product::factory()->create([
            'organization_id' => $segment->organization_id,
            'product_type' => 'metered',
            'billable_metric_id' => App\Models\BillableMetric::factory()->create(['organization_id' => $segment->organization_id])->id,
        ])->id,
        'billing_timing' => 'advance',
    ]);
    $metered = BillingSegment::factory()->create([
        'organization_id' => $segment->organization_id,
        'customer_id' => $segment->customer_id,
        'contract_id' => $segment->contract_id,
        'contract_rate_card_id' => App\Models\ContractRateCard::factory()->create([
            'organization_id' => $segment->organization_id,
            'contract_id' => $segment->contract_id,
            'rate_card_id' => $meteredCard->id,
        ])->id,
        'rate_card_rate_id' => RateCardRate::factory()->create([
            'organization_id' => $segment->organization->id,
            'rate_card_id' => $meteredCard->id,
        ])->id,
        'currency' => 'EUR',
        'status' => 'pending',
    ]);

    expect(BillingSegment::query()->awaitingInvoicing()->where('billing_segments.customer_id', $segment->customer_id)->count())->toBe(0);

    // ...but the same metered card in ARREARS does await.
    $meteredCard->update(['billing_timing' => 'arrears']);
    expect(BillingSegment::query()->awaitingInvoicing()->where('billing_segments.customer_id', $segment->customer_id)->count())->toBe(1);
});

// -- Billing::ElapsedPeriodRatio ---------------------------------------------

it('computes inclusive day progress clamped to the window', function (): void {
    $ratio = App\Models\Billing\ElapsedPeriodRatio::class;

    $from = CarbonImmutable::parse('2026-08-01', 'UTC');
    $to = CarbonImmutable::parse('2026-08-20', 'UTC');

    expect($ratio::calculate($from, $to, CarbonImmutable::parse('2026-08-21', 'UTC')))->toEqualWithDelta(1.0, 1e-12)
        ->and($ratio::calculate($from, $to, CarbonImmutable::parse('2026-07-30', 'UTC')))->toEqualWithDelta(0.0, 1e-12)
        // Inclusive: Aug 1 is day 1 of 20 (to_date - from_date + 1 denominator).
        ->and($ratio::calculate($from, $to, CarbonImmutable::parse('2026-08-01', 'UTC')))->toEqualWithDelta(1 / 20, 1e-12)
        ->and($ratio::calculate($from, $to, CarbonImmutable::parse('2026-08-15', 'UTC')))->toEqualWithDelta(15 / 20, 1e-12)
        // A shortened service window may supply the full-period denominator.
        ->and($ratio::calculate($from, $to, CarbonImmutable::parse('2026-08-10', 'UTC'), 31))->toEqualWithDelta(10 / 31, 1e-12);
});
