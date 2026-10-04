<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\BillableMetric;
use App\Models\CachedAggregation;
use App\Models\BillingPeriodBoundaries;
use App\Services\Events\Stores\PostgresStore;
use App\Services\Fees\ChargeService\Aggregator;
use App\Services\Fees\ChargeService\MeteredItem;
use App\Models\Billing\Context as BillingContext;

uses()->group('ledger:svc:Fees.ChargeService.Aggregator');

/**
 * The aggregation decision contract (finding 12): the fee engine's
 * Aggregator aggregates the events LIVE for periodic in-arrears billing —
 * cached_aggregations rows are NEVER consulted there (they carry the
 * pay-in-advance current-usage state and the recurring weighted-sum
 * carry-over only) — and emits the real events count plus the percentage
 * running_total.
 */
function aggregatorMetric(array $attributes = []): BillableMetric
{
    return BillableMetric::factory()->create($attributes + [
        'code' => 'metered_calls',
        'field_name' => 'calls',
        'aggregation_type' => 1,
    ]);
}

function aggregatorCustomer(BillableMetric $metric): Customer
{
    return Customer::factory()->create(['organization_id' => $metric->organization_id]);
}

function aggregatorSubscription(Customer $customer): Subscription
{
    return Subscription::factory()->create([
        'organization_id' => $customer->organization_id,
        'customer_id' => $customer->id,
        'external_id' => 'agg-sub-1',
        'started_at' => Carbon\CarbonImmutable::parse('2025-05-01 00:00:00 UTC'),
    ]);
}

function aggregatorCharge(BillableMetric $metric, array $attributes = []): Charge
{
    return Charge::factory()->standard()->create([
        'organization_id' => $metric->organization_id,
        'billable_metric_id' => $metric->id,
        'properties' => $attributes['properties'] ?? [],
    ] + $attributes);
}

function aggregatorBoundaries(): BillingPeriodBoundaries
{
    return new BillingPeriodBoundaries(
        fromDatetime: Carbon\CarbonImmutable::parse('2025-05-01 00:00:00 UTC'),
        toDatetime: Carbon\CarbonImmutable::parse('2025-06-01 00:00:00 UTC'),
        chargesFromDatetime: Carbon\CarbonImmutable::parse('2025-05-01 00:00:00 UTC'),
        chargesToDatetime: Carbon\CarbonImmutable::parse('2025-06-01 00:00:00 UTC'),
        chargesDuration: 31,
        timestamp: Carbon\CarbonImmutable::parse('2025-05-01 00:00:00 UTC'),
    );
}

function aggregatorEvent(Subscription $subscription, string $timestamp, string|int $value): Event
{
    return Event::factory()->create([
        'organization_id' => $subscription->organization_id,
        'external_subscription_id' => $subscription->external_id,
        'code' => 'metered_calls',
        'timestamp' => Carbon\CarbonImmutable::parse($timestamp),
        'properties' => ['calls' => $value],
    ]);
}

function aggregatorAggregator(Charge $charge, Subscription $subscription, array $options = []): Aggregator
{
    return new Aggregator(
        MeteredItem::fromCharge($charge, aggregatorBoundaries()),
        $subscription,
        new App\Services\Fees\ChargeService\Options($options['context'] ?? null),
    );
}

/** The contract scenario's metered input: three events summing 21 in May. */
function aggregatorSeedMayEvents(Subscription $subscription): void
{
    aggregatorEvent($subscription, '2025-05-10 09:00:00 UTC', 10);
    aggregatorEvent($subscription, '2025-05-11 09:00:00 UTC', 10);
    aggregatorEvent($subscription, '2025-05-14 09:00:00 UTC', 1);
}

it('aggregates the events live on the arrears path with the real events count', function (): void {
    $metric = aggregatorMetric();
    $subscription = aggregatorSubscription(aggregatorCustomer($metric));
    $charge = aggregatorCharge($metric);
    aggregatorSeedMayEvents($subscription);

    $result = aggregatorAggregator($charge, $subscription)->aggregate();

    expect($result->aggregation)->toBe('21')
        ->and($result->count)->toBe(3);
});

it('ignores the cached_aggregations row on the arrears path (finding 12)', function (): void {
    $metric = aggregatorMetric();
    $subscription = aggregatorSubscription(aggregatorCustomer($metric));
    $charge = aggregatorCharge($metric);
    aggregatorSeedMayEvents($subscription);

    // The contract scenarios seed such a row with the same sum; here it
    // carries a WRONG value to prove it is not the source.
    CachedAggregation::factory()->create([
        'organization_id' => $charge->organization_id,
        'charge_id' => $charge->id,
        'external_subscription_id' => $subscription->external_id,
        'timestamp' => Carbon\CarbonImmutable::parse('2025-05-14 09:00:00 UTC'),
        'current_aggregation' => '999',
        'max_aggregation' => '999',
    ]);

    $result = aggregatorAggregator($charge, $subscription)->aggregate();

    expect($result->aggregation)->toBe('21')
        ->and($result->count)->toBe(3);
});

it('builds the percentage running_total from the first event values', function (): void {
    $metric = aggregatorMetric();
    $subscription = aggregatorSubscription(aggregatorCustomer($metric));
    $charge = aggregatorCharge($metric, ['properties' => ['free_units_per_events' => 2]]);
    aggregatorSeedMayEvents($subscription);

    $result = aggregatorAggregator($charge, $subscription)->aggregate();

    // SumService#running_total_per_events — cumulative sums limited to the
    // free units count: 10, then 10 + 10.
    expect($result->aggregation)->toBe('21')
        ->and($result->count)->toBe(3)
        ->and($result->options['running_total'][0])->toEqualWithDelta(10.0, 0.0000001)
        ->and($result->options['running_total'][1])->toEqualWithDelta(20.0, 0.0000001);
});

it('returns the zero aggregation for an empty period', function (): void {
    $metric = aggregatorMetric();
    $subscription = aggregatorSubscription(aggregatorCustomer($metric));
    $charge = aggregatorCharge($metric);

    $result = aggregatorAggregator($charge, $subscription)->aggregate();

    expect($result->aggregation)->toBe('0')
        ->and($result->count)->toBe(0);
});

it('adjusts the pay-in-advance current usage by the cached row', function (): void {
    $metric = aggregatorMetric();
    $subscription = aggregatorSubscription(aggregatorCustomer($metric));
    $charge = aggregatorCharge($metric, ['pay_in_advance' => true]);
    aggregatorSeedMayEvents($subscription);

    // Cached state from earlier in-period pay-in-advance events: 15 seen,
    // 15 max applied.
    CachedAggregation::factory()->create([
        'organization_id' => $charge->organization_id,
        'charge_id' => $charge->id,
        'external_subscription_id' => $subscription->external_id,
        'timestamp' => Carbon\CarbonImmutable::parse('2025-05-14 09:00:00 UTC'),
        'current_aggregation' => '15',
        'max_aggregation' => '15',
    ]);

    $result = aggregatorAggregator($charge, $subscription, ['context' => 'current_usage'])->aggregate();

    // total (21) - cached current (15) + cached max (15) = 21 billed units;
    // current usage units stay the full aggregation.
    expect($result->aggregation)->toBe('21')
        ->and($result->currentUsageUnits)->toBe('21')
        ->and($result->count)->toBe(3);
});

it('carries the recurring weighted-sum value over from the cached row', function (): void {
    $metric = aggregatorMetric([
        'aggregation_type' => 5,
        'field_name' => 'calls',
        'recurring' => true,
    ]);
    $subscription = aggregatorSubscription(aggregatorCustomer($metric));
    $charge = aggregatorCharge($metric);

    CachedAggregation::factory()->create([
        'organization_id' => $charge->organization_id,
        'charge_id' => $charge->id,
        'external_subscription_id' => $subscription->external_id,
        'timestamp' => Carbon\CarbonImmutable::parse('2025-04-10 00:00:00 UTC'),
        'current_aggregation' => '100',
    ]);

    aggregatorEvent($subscription, '2025-05-10 00:00:00 UTC', 10);

    $result = aggregatorAggregator($charge, $subscription)->aggregate();

    // Initial value 100 + 10 units held for the remaining 22 days of a
    // 31-day period (weighted); the period total carries over 100 + the 10
    // new units.
    $expected = (float) (100 + 10 * 22 / 31);
    expect((float) $result->aggregation)->toBe($expected)
        ->and((float) $result->totalAggregatedUnits)->toBe(110.0)
        ->and($result->count)->toBe(1)
        ->and($result->recurringUpdatedAt)->not->toBeNull();
});

it('counts the events for a count-aggregation metric', function (): void {
    $metric = aggregatorMetric(['aggregation_type' => 0, 'field_name' => null]);
    $subscription = aggregatorSubscription(aggregatorCustomer($metric));
    $charge = aggregatorCharge($metric, ['properties' => ['free_units_per_events' => 2]]);
    aggregatorSeedMayEvents($subscription);

    $result = aggregatorAggregator($charge, $subscription)->aggregate();

    expect($result->aggregation)->toBe('3')
        ->and($result->count)->toBe(3)
        ->and($result->options['running_total'])->toBe([1, 2, 3]);
});

it('takes the maximum event value with its events count', function (): void {
    $metric = aggregatorMetric(['aggregation_type' => 2]);
    $subscription = aggregatorSubscription(aggregatorCustomer($metric));
    $charge = aggregatorCharge($metric);
    aggregatorSeedMayEvents($subscription);

    $result = aggregatorAggregator($charge, $subscription)->aggregate();

    expect($result->aggregation)->toBe('10')
        ->and($result->count)->toBe(3);
});

it('bypasses the aggregation for non-recurring metrics when asked', function (): void {
    $metric = aggregatorMetric();
    $subscription = aggregatorSubscription(aggregatorCustomer($metric));
    $charge = aggregatorCharge($metric);
    aggregatorSeedMayEvents($subscription);

    // Rails: empty_results — a null result with zero units and count.
    $service = App\Services\BillableMetrics\AggregationFactory::newInstance(
        meteredItem: MeteredItem::fromCharge($charge, aggregatorBoundaries()),
        billingContext: BillingContext::fromSubscription($subscription),
        boundaries: [
            'from_datetime' => Carbon\CarbonImmutable::parse('2025-05-01 00:00:00 UTC'),
            'to_datetime' => Carbon\CarbonImmutable::parse('2025-06-01 00:00:00 UTC'),
            'charges_duration' => 31,
        ],
        filters: ['charge_id' => $charge->id],
    );

    $empty = $service->emptyResults();

    expect($empty->aggregation)->toBe('0')
        ->and($empty->count)->toBe(0)
        ->and($empty->options['running_total'])->toBe([]);
});

it('runs the clickhouse store through the faked HTTP interface', function (): void {
    $metric = aggregatorMetric();
    $subscription = aggregatorSubscription(aggregatorCustomer($metric));

    $store = new PostgresStore(
        billingContext: BillingContext::fromSubscription($subscription),
        boundaries: [],
        code: 'metered_calls',
    );

    // The store implements the full aggregation API (no placeholders left).
    expect($store->precomputed())->toBeFalse();

    // The ClickHouseStore queries the ClickHouse HTTP interface; fake it so
    // the full sum() path (CTE build, FORMAT JSON decode, result mapping)
    // runs without a live server. The real-container smoke lives in
    // ClickHouseStoreTest (skipped without LAGO_CLICKHOUSE_TEST_HOST).
    Illuminate\Support\Facades\Http::fake([
        '*' => Illuminate\Support\Facades\Http::response([
            'data' => [['value' => '21', 'events_count' => '3']],
        ]),
    ]);

    $clickhouse = new App\Services\Events\Stores\ClickHouseStore(
        billingContext: BillingContext::fromSubscription($subscription),
        boundaries: [],
        code: 'metered_calls',
    );

    $result = $clickhouse->sum();

    expect($result->value)->toBe('21')
        ->and($result->eventsCount)->toBe(3);
});
