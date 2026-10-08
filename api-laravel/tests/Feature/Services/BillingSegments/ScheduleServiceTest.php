<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\RateCard;
use Carbon\CarbonImmutable;
use App\Models\Organization;
use App\Models\RateCardRate;
use App\Models\BillingSegment;
use Illuminate\Support\Facades\DB;
use App\Services\BillingSegments\ScheduleService;

/**
 * Port of spec/services/billing_segments/schedule_service_spec.rb.
 */
function scheduleFixture(): array
{
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->create(['organization_id' => $organization->id, 'timezone' => null]);
    $rateCard = RateCard::factory()->create([
        'organization_id' => $organization->id,
        'billing_timing' => 'arrears',
        'proration' => false,
    ]);
    $contract = App\Models\Contract::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'started_at' => CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC'),
    ]);

    RateCardRate::factory()->create([
        'organization_id' => $organization->id,
        'rate_card_id' => $rateCard->id,
        'effective_from' => CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC'),
        'billing_interval_count' => 1,
        'billing_interval_unit' => 'month',
    ]);

    $contractRateCard = App\Models\ContractRateCard::factory()->create([
        'organization_id' => $organization->id,
        'contract_id' => $contract->id,
        'rate_card_id' => $rateCard->id,
        'effective_date' => '2026-01-01',
        'billing_anchor_date' => '2026-01-01',
        'next_billing_at' => CarbonImmutable::parse('2026-02-01 00:00:00', 'UTC'),
    ]);

    return compact('organization', 'customer', 'rateCard', 'contract', 'contractRateCard');
}

function scheduleAddRate(RateCard $rateCard, CarbonImmutable $effectiveFrom): RateCardRate
{
    return RateCardRate::factory()->create([
        'organization_id' => $rateCard->organization_id,
        'rate_card_id' => $rateCard->id,
        'effective_from' => $effectiveFrom,
        'billing_interval_count' => 1,
        'billing_interval_unit' => 'month',
    ]);
}

it('writes the card due segments', function (): void {
    extract(scheduleFixture());

    $result = ScheduleService::call(customer: $customer, timestamp: CarbonImmutable::parse('2026-03-01 00:00:00', 'UTC'));

    expect(array_map(
        fn ($segment) => [$segment->started_at->format('Y-m-d H:i'), $segment->billing_at->format('Y-m-d H:i')],
        $result->billing_segments,
    ))->toBe([
        ['2026-01-01 00:00', '2026-02-01 00:00'],
        ['2026-02-01 00:00', '2026-03-01 00:00'],
    ]);
});

it('advances the card clock past the run', function (): void {
    extract(scheduleFixture());

    ScheduleService::call(customer: $customer, timestamp: CarbonImmutable::parse('2026-03-01 00:00:00', 'UTC'));

    expect(CarbonImmutable::parse($contractRateCard->fresh()->next_billing_at)->equalTo(CarbonImmutable::parse('2026-04-01 00:00:00', 'UTC')))->toBeTrue();
});

it('is idempotent a second run writes nothing more', function (): void {
    extract(scheduleFixture());

    ScheduleService::call(customer: $customer, timestamp: CarbonImmutable::parse('2026-03-01 00:00:00', 'UTC'));

    $before = BillingSegment::query()->count();
    ScheduleService::call(customer: $customer, timestamp: CarbonImmutable::parse('2026-03-01 00:00:00', 'UTC'));

    expect(BillingSegment::query()->count())->toBe($before);
});

it('writes nothing when a rate is added before the saved clock', function (): void {
    extract(scheduleFixture());

    scheduleAddRate($rateCard, CarbonImmutable::parse('2026-02-15 00:00:00', 'UTC'));
    $contractRateCard->update(['next_billing_at' => CarbonImmutable::parse('2026-03-01 00:00:00', 'UTC')]);

    $result = ScheduleService::call(customer: $customer, timestamp: CarbonImmutable::parse('2026-02-20 00:00:00', 'UTC'));

    expect($result->billing_segments)->toBe([]);
});

it('writes the slice late on the tick the clock does reach keeping its own billing date', function (): void {
    extract(scheduleFixture());

    scheduleAddRate($rateCard, CarbonImmutable::parse('2026-02-15 00:00:00', 'UTC'));
    $contractRateCard->update(['next_billing_at' => CarbonImmutable::parse('2026-03-01 00:00:00', 'UTC')]);

    $result = ScheduleService::call(customer: $customer, timestamp: CarbonImmutable::parse('2026-03-01 00:00:00', 'UTC'));

    $late = null;
    foreach ($result->billing_segments as $segment) {
        if ($segment->billing_at->equalTo(CarbonImmutable::parse('2026-02-15 00:00:00', 'UTC'))) {
            $late = $segment;
        }
    }

    expect($late)->not->toBeNull()
        ->and($late->started_at->toDateString())->toBe('2026-02-01');
});

it('bills the next cycle instead of failing when a rate lands inside an already billed advance cycle', function (): void {
    extract(scheduleFixture());
    $rateCard->update(['billing_timing' => 'advance']);

    ScheduleService::callBang(customer: $customer, timestamp: CarbonImmutable::parse('2026-02-01 00:00:00', 'UTC'));
    scheduleAddRate($rateCard, CarbonImmutable::parse('2026-02-10 00:00:00', 'UTC'));

    $result = ScheduleService::callBang(customer: $customer, timestamp: CarbonImmutable::parse('2026-03-01 00:00:00', 'UTC'));

    expect(array_map(fn ($segment) => $segment->started_at->toDateString(), $result->billing_segments))
        ->toBe(['2026-03-01']);

    // The settled cycle stays untouched.
    $settledEnd = BillingSegment::query()
        ->where('customer_id', $customer->id)
        ->where('started_at', CarbonImmutable::parse('2026-02-01 00:00:00', 'UTC'))
        ->value('ended_at');

    expect(CarbonImmutable::parse($settledEnd)->toDateString())->toBe('2026-02-28');
});

it('bills the priced card and leaves the unpriced one alone', function (): void {
    extract(scheduleFixture());

    $unpricedCard = App\Models\ContractRateCard::factory()->create([
        'organization_id' => $organization->id,
        'contract_id' => $contract->id,
        'rate_card_id' => RateCard::factory()->create([
            'organization_id' => $organization->id,
            'billing_timing' => 'arrears',
        ])->id,
        'effective_date' => '2026-01-01',
        'billing_anchor_date' => '2026-01-01',
        'next_billing_at' => CarbonImmutable::parse('2026-02-01 00:00:00', 'UTC'),
    ]);

    $result = ScheduleService::callBang(customer: $customer, timestamp: CarbonImmutable::parse('2026-03-01 00:00:00', 'UTC'));

    expect(count(array_unique(array_map(fn ($segment) => $segment->contract_rate_card_id, $result->billing_segments))))
        ->toBe(1)
        ->and($result->billing_segments[0]->contract_rate_card_id)->toBe($contractRateCard->id)
        // The unpriced card's clock stays alone, so it bills once it has a price.
        ->and(CarbonImmutable::parse($unpricedCard->fresh()->next_billing_at)->equalTo(CarbonImmutable::parse('2026-02-01 00:00:00', 'UTC')))->toBeTrue();
});

it('maps the overlap constraint violation to the overlapping_periods failure', function (): void {
    // Rails stubs MissingBillableSegmentsService to reach the DB constraint
    // (last-resort guard behind the filter); here the same mapping is driven
    // through the service's constraint matcher with a real QueryException —
    // the constraint firing at all is covered in CreateServiceTest.
    $service = new ScheduleService(
        customer: Customer::factory()->create(),
        timestamp: CarbonImmutable::parse('2026-03-01 00:00:00', 'UTC'),
    );

    $method = new ReflectionMethod(ScheduleService::class, 'overlappingPeriods');
    $method->setAccessible(true);

    $constraintError = new Illuminate\Database\QueryException(
        'pgsql',
        'insert into billing_segments ...',
        [],
        new Exception('ERROR:  conflicting key value violates exclusion constraint "billing_segments_no_overlapping_periods"'),
    );
    $unrelatedError = new Illuminate\Database\QueryException(
        'pgsql',
        'insert into billing_segments ...',
        [],
        new Exception('ERROR:  duplicate key value violates unique constraint "other"'),
    );

    expect($method->invoke($service, $constraintError))->toBeTrue()
        ->and($method->invoke($service, $unrelatedError))->toBeFalse();
});
