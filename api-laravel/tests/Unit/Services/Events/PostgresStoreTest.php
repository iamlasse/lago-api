<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\BillableMetric;
use App\Services\Events\Stores\PostgresStore;
use App\Models\Billing\Context as BillingContext;

uses()->group('ledger:svc:Events.Stores.PostgresStore');

/**
 * Port of Rails' spec/services/events/stores/postgres_store_spec.rb and the
 * shared "an event store" examples (sum / count / max / last / events_values /
 * unique_count / weighted_sum / prorated_sum / grouped variants).
 *
 * The seeded input mirrors the Rails shared example: five events, values
 * 1..5 inside the period (from 2023-03-15 to end of month, 31 days), plus
 * region/country/city properties.
 */
function storeMetric(): BillableMetric
{
    return BillableMetric::factory()->create([
        'code' => 'bm:code',
        'field_name' => 'value',
        'aggregation_type' => 1,
    ]);
}

function storeCustomer(Organization $organization): Customer
{
    return Customer::factory()->create([
        'organization_id' => $organization->id,
        'timezone' => 'UTC',
    ]);
}

function storeSubscription(Customer $customer): Subscription
{
    return Subscription::factory()->create([
        'organization_id' => $customer->organization_id,
        'customer_id' => $customer->id,
        'started_at' => Carbon\CarbonImmutable::parse('2023-03-15 00:00:00 UTC'),
    ]);
}

function storeEvent(
    Organization $organization,
    Subscription $subscription,
    string $timestamp,
    string|int $value,
    array $properties = [],
    ?string $transactionId = null,
): Event {
    return Event::factory()->create([
        'organization_id' => $organization->id,
        'external_subscription_id' => $subscription->external_id,
        'transaction_id' => $transactionId ?? 'tr_'.bin2hex(random_bytes(10)),
        'code' => 'bm:code',
        'timestamp' => Carbon\CarbonImmutable::parse($timestamp),
        'properties' => $properties + ['value' => $value],
    ]);
}

/**
 * The event store over the shared fixture, sum-aggregating the `value`
 * property (Rails: aggregation_property + numeric_property set).
 */
function storeStore(Subscription $subscription, array $boundaries = [], array $filters = []): PostgresStore
{
    $store = new PostgresStore(
        billingContext: BillingContext::fromSubscription($subscription),
        boundaries: $boundaries ?: [
            'from_datetime' => Carbon\CarbonImmutable::parse('2023-03-15 00:00:00 UTC'),
            'to_datetime' => Carbon\CarbonImmutable::parse('2023-03-31 23:59:59.999999 UTC'),
            'charges_duration' => 31,
        ],
        code: 'bm:code',
        filters: $filters,
    );

    $store->setAggregationProperty('value');
    $store->setNumericProperty(true);

    return $store;
}

/** The Rails shared-example fixture: values 1..5 across the period. */
function storeSeedPeriod(Organization $organization, Subscription $subscription): array
{
    return [
        storeEvent($organization, $subscription, '2023-03-16 12:00:00 UTC', 1, ['region' => 'europe', 'country' => 'france', 'city' => 'paris']),
        storeEvent($organization, $subscription, '2023-03-17 12:00:00 UTC', 2),
        storeEvent($organization, $subscription, '2023-03-18 12:00:00 UTC', 3, ['region' => 'europe', 'country' => 'france']),
        storeEvent($organization, $subscription, '2023-03-19 12:00:00 UTC', 4),
        storeEvent($organization, $subscription, '2023-03-20 12:00:00 UTC', 5, ['region' => 'europe', 'country' => 'united kingdom', 'city' => 'london']),
    ];
}

it('sums the event properties with the events count', function (): void {
    $metric = storeMetric();
    $customer = storeCustomer($metric->organization);
    $subscription = storeSubscription($customer);
    storeSeedPeriod($metric->organization, $subscription);

    $result = storeStore($subscription)->sum();

    expect($result->value)->toBe('15')
        ->and($result->eventsCount)->toBe(5);
});

it('sums without the events count when asked', function (): void {
    $metric = storeMetric();
    $customer = storeCustomer($metric->organization);
    $subscription = storeSubscription($customer);
    storeSeedPeriod($metric->organization, $subscription);

    $result = storeStore($subscription)->sum(withCount: false);

    expect($result->value)->toBe('15')
        ->and($result->eventsCount)->toBeNull();
});

it('excludes events before from_datetime and includes them when the boundary is off', function (): void {
    $metric = storeMetric();
    $customer = storeCustomer($metric->organization);
    $subscription = storeSubscription($customer);
    storeSeedPeriod($metric->organization, $subscription);

    storeEvent($metric->organization, $subscription, '2023-03-14 12:00:00 UTC', 100, ['region' => 'europe', 'country' => 'france']);

    $store = storeStore($subscription);
    expect($store->sum()->value)->toBe('15')
        ->and($store->sum()->eventsCount)->toBe(5);

    $store->setUseFromBoundary(false);
    expect($store->sum()->value)->toBe('115')
        ->and($store->sum()->eventsCount)->toBe(6);
});

it('excludes events after to_datetime', function (): void {
    $metric = storeMetric();
    $customer = storeCustomer($metric->organization);
    $subscription = storeSubscription($customer);
    storeSeedPeriod($metric->organization, $subscription);

    $store = storeStore($subscription, boundaries: [
        'from_datetime' => Carbon\CarbonImmutable::parse('2023-03-15 00:00:00 UTC'),
        'to_datetime' => Carbon\CarbonImmutable::parse('2023-03-18 12:00:00 UTC'),
        'charges_duration' => 31,
    ]);

    expect($store->sum()->value)->toBe('6')
        ->and($store->sum()->eventsCount)->toBe(3);
});

it('uses max_timestamp instead of to_datetime', function (): void {
    $metric = storeMetric();
    $customer = storeCustomer($metric->organization);
    $subscription = storeSubscription($customer);
    storeSeedPeriod($metric->organization, $subscription);

    $store = storeStore($subscription, boundaries: [
        'from_datetime' => Carbon\CarbonImmutable::parse('2023-03-15 00:00:00 UTC'),
        'to_datetime' => Carbon\CarbonImmutable::parse('2023-03-31 23:59:59.999999 UTC'),
        'max_timestamp' => Carbon\CarbonImmutable::parse('2023-03-18 12:00:00 UTC'),
        'charges_duration' => 31,
    ]);

    expect($store->sum()->value)->toBe('6')
        ->and($store->sum()->eventsCount)->toBe(3);
});

it('counts the events in the period', function (): void {
    $metric = storeMetric();
    $customer = storeCustomer($metric->organization);
    $subscription = storeSubscription($customer);
    storeSeedPeriod($metric->organization, $subscription);

    $result = storeStore($subscription)->count();

    expect((string) $result->value)->toBe('5')
        ->and($result->eventsCount)->toBe(5);
});

it('takes the maximum property value', function (): void {
    $metric = storeMetric();
    $customer = storeCustomer($metric->organization);
    $subscription = storeSubscription($customer);
    storeSeedPeriod($metric->organization, $subscription);

    $result = storeStore($subscription)->max();

    expect($result->value)->toBe('5')
        ->and($result->eventsCount)->toBe(5);
});

it('takes the last event property value', function (): void {
    $metric = storeMetric();
    $customer = storeCustomer($metric->organization);
    $subscription = storeSubscription($customer);
    storeSeedPeriod($metric->organization, $subscription);

    $result = storeStore($subscription)->last();

    expect($result->value)->toBe('5')
        ->and($result->eventsCount)->toBe(5);
});

it('lists the event values in order and honors the limit', function (): void {
    $metric = storeMetric();
    $customer = storeCustomer($metric->organization);
    $subscription = storeSubscription($customer);
    storeSeedPeriod($metric->organization, $subscription);

    $store = storeStore($subscription);

    expect($store->eventsValues())->toBe(['1', '2', '3', '4', '5'])
        ->and($store->eventsValues(limit: 3))->toBe(['1', '2', '3']);
});

it('groups the sum by a property with the per-group events count', function (): void {
    $metric = storeMetric();
    $customer = storeCustomer($metric->organization);
    $subscription = storeSubscription($customer);
    storeSeedPeriod($metric->organization, $subscription);

    $results = storeStore($subscription, filters: ['grouped_by' => ['region']])->groupedSum();

    $byRegion = collect($results)->keyBy(fn ($r) => $r->groups['region'] ?? null);

    expect($results)->toHaveCount(2)
        ->and($byRegion['europe']->value)->toBe('9')
        ->and($byRegion['europe']->eventsCount)->toBe(3)
        ->and($byRegion[null]->value)->toBe('6')
        ->and($byRegion[null]->eventsCount)->toBe(2);
});

it('scopes the aggregation to grouped_by_values', function (): void {
    $metric = storeMetric();
    $customer = storeCustomer($metric->organization);
    $subscription = storeSubscription($customer);
    storeSeedPeriod($metric->organization, $subscription);

    $store = storeStore($subscription, filters: [
        'grouped_by' => ['region'],
        'grouped_by_values' => ['country' => 'france'],
    ]);

    expect($store->sum()->value)->toBe('4')
        ->and($store->sum()->eventsCount)->toBe(2);
});

it('applies matching and ignored property filters', function (): void {
    $metric = storeMetric();
    $customer = storeCustomer($metric->organization);
    $subscription = storeSubscription($customer);
    storeSeedPeriod($metric->organization, $subscription);

    $matching = storeStore($subscription, filters: [
        'matching_filters' => ['country' => ['france']],
    ]);
    expect($matching->sum()->value)->toBe('4');

    $ignored = storeStore($subscription, filters: [
        'ignored_filters' => [['city' => ['paris']]],
    ]);
    // The paris event (value 1) is ignored → 2 + 3 + 4 + 5.
    expect($ignored->sum()->value)->toBe('14');
});

it('counts unique properties with add and remove operations', function (): void {
    $metric = storeMetric();
    $metric->field_name = 'item_id';
    $metric->save();
    $customer = storeCustomer($metric->organization);
    $subscription = storeSubscription($customer);

    $event = fn (string $ts, string $item, ?string $operation = null) => storeEvent(
        $metric->organization,
        $subscription,
        $ts,
        $item,
        array_filter(['item_id' => $item, 'operation_type' => $operation]),
    );

    $event('2023-03-16 12:00:00 UTC', '001');
    $event('2023-03-17 12:00:00 UTC', '001'); // duplicate add → 0
    $event('2023-03-17 13:00:00 UTC', '002');
    $event('2023-03-18 12:00:00 UTC', '001', 'remove');
    $event('2023-03-19 12:00:00 UTC', '002', 'remove');

    $store = storeStore($subscription);
    $store->setNumericProperty(false);
    $store->setAggregationProperty('item_id');
    $store->setUseFromBoundary(false);

    // 001 added once and removed once, 002 added once and removed once → 0.
    expect((string) $store->uniqueCount()->value)->toBe('0');

    $event('2023-03-20 12:00:00 UTC', '003');

    expect((string) $store->uniqueCount()->value)->toBe('1');
});

it('computes the weighted sum over the period', function (): void {
    $metric = BillableMetric::factory()->weightedSumAgg()->create(['code' => 'bm:code', 'field_name' => 'value']);
    $customer = storeCustomer($metric->organization);
    $subscription = storeSubscription($customer);

    storeEvent($metric->organization, $subscription, '2023-03-16 00:00:00 UTC', 10);
    storeEvent($metric->organization, $subscription, '2023-03-17 00:00:00 UTC', -4);

    $store = new PostgresStore(
        billingContext: BillingContext::fromSubscription($subscription),
        boundaries: [
            'from_datetime' => Carbon\CarbonImmutable::parse('2023-03-15 00:00:00 UTC'),
            'to_datetime' => Carbon\CarbonImmutable::parse('2023-03-22 00:00:00 UTC'),
            'charges_duration' => 7,
        ],
        code: 'bm:code',
    );
    $store->setAggregationProperty('value');
    $store->setNumericProperty(true);

    $result = $store->weightedSum(0);

    // 10 units for 1 day + 6 units for 5 days over a 7-day period.
    expect((float) $result->value)->toBe((float) (10 + 30) / 7)
        ->and((string) $result->variation)->toBe('6')
        ->and($result->eventsCount)->toBe(2);
});

it('prorates the unique count over the period days', function (): void {
    $metric = BillableMetric::factory()->uniqueCount()->create(['code' => 'bm:code', 'field_name' => 'item_id']);
    $customer = storeCustomer($metric->organization);
    $subscription = storeSubscription($customer);

    storeEvent($metric->organization, $subscription, '2023-03-16 00:00:00 UTC', '001', ['item_id' => '001']);

    $store = new PostgresStore(
        billingContext: BillingContext::fromSubscription($subscription),
        boundaries: [
            'from_datetime' => Carbon\CarbonImmutable::parse('2023-03-15 00:00:00 UTC'),
            'to_datetime' => Carbon\CarbonImmutable::parse('2023-03-22 00:00:00 UTC'),
            'charges_duration' => 7,
        ],
        code: 'bm:code',
    );
    $store->setAggregationProperty('item_id');
    $store->setUseFromBoundary(false);

    // Rails' UniqueCountQuery period_ratio counts the add from its day
    // through (LEAD + 1 day) — the end-of-period boundary +1 day → the full
    // 7/7 for an event held to the end.
    expect((float) $store->proratedUniqueCount()->value)->toBe(1.0);
});

it('groups the unique count by a property', function (): void {
    $metric = BillableMetric::factory()->uniqueCount()->create(['code' => 'bm:code', 'field_name' => 'item_id']);
    $customer = storeCustomer($metric->organization);
    $subscription = storeSubscription($customer);

    storeEvent($metric->organization, $subscription, '2023-03-16 00:00:00 UTC', '001', ['item_id' => '001', 'region' => 'europe']);
    storeEvent($metric->organization, $subscription, '2023-03-17 00:00:00 UTC', '002', ['item_id' => '002', 'region' => 'europe']);
    storeEvent($metric->organization, $subscription, '2023-03-18 00:00:00 UTC', '003', ['item_id' => '003']);

    $store = new PostgresStore(
        billingContext: BillingContext::fromSubscription($subscription),
        boundaries: [
            'from_datetime' => Carbon\CarbonImmutable::parse('2023-03-15 00:00:00 UTC'),
            'to_datetime' => Carbon\CarbonImmutable::parse('2023-03-22 00:00:00 UTC'),
            'charges_duration' => 7,
        ],
        code: 'bm:code',
        filters: ['grouped_by' => ['region']],
    );
    $store->setAggregationProperty('item_id');
    $store->setUseFromBoundary(false);

    $results = collect($store->groupedUniqueCount())->keyBy(fn ($r) => $r->groups['region'] ?? null);

    expect($results['europe']->value)->toBe('2')
        ->and($results['europe']->eventsCount)->toBe('2')
        ->and($results[null]->value)->toBe('1');
});

it('prorates the sum by the day ratio', function (): void {
    $metric = storeMetric();
    $customer = storeCustomer($metric->organization);
    $subscription = storeSubscription($customer);

    storeEvent($metric->organization, $subscription, '2023-03-16 00:00:00 UTC', 10);
    storeEvent($metric->organization, $subscription, '2023-03-31 00:00:00 UTC', 5);

    $store = storeStore($subscription);
    $result = $store->proratedSum(31);

    // Rails duration_ratio_sql counts from the EVENT day through the end of
    // the period: (period_end - event_day + 1) / duration — Mar 16 covers 16
    // of 31 days, Mar 31 covers 1.
    expect((float) $result->proratedValue)->toBe((float) (10 * (16 / 31) + 5 * (1 / 31)))
        ->and($result->value)->toBe('15')
        ->and($result->eventsCount)->toBe(2);
});

it('returns zero aggregates over an empty period', function (): void {
    $metric = storeMetric();
    $customer = storeCustomer($metric->organization);
    $subscription = storeSubscription($customer);

    $store = storeStore($subscription);

    expect($store->sum()->value)->toBe(0)
        ->and($store->sum()->eventsCount)->toBe(0)
        ->and((string) $store->count()->value)->toBe('0')
        ->and($store->max()->value)->toBe(0)
        ->and($store->last()->value)->toBeNull()
        ->and($store->eventsValues())->toBe([]);
});

it('lists distinct code and property combinations for the filters', function (): void {
    $metric = storeMetric();
    $customer = storeCustomer($metric->organization);
    $subscription = storeSubscription($customer);
    storeSeedPeriod($metric->organization, $subscription);

    $combinations = storeStore($subscription)->distinctCodesAndPropertyCombinations(
        codes: ['bm:code'],
        filterKeys: ['country'],
        withLastSeenAt: false,
    );

    $byCountry = collect($combinations)->keyBy(fn ($c) => $c['combination']['country'] ?? '(default)');

    expect($combinations)->toHaveCount(3)
        ->and($byCountry['france']['code'])->toBe('bm:code')
        ->and($byCountry['(default)']['combination'])->toBe([])
        ->and($byCountry['united kingdom']['last_seen_at'])->toBeNull();
});

it('reports whether an earlier unique property is still active', function (): void {
    $metric = storeMetric();
    $customer = storeCustomer($metric->organization);
    $subscription = storeSubscription($customer);

    $store = storeStore($subscription);
    $store->setNumericProperty(false);
    $store->setAggregationProperty('item_id');

    $first = storeEvent($metric->organization, $subscription, '2023-03-16 12:00:00 UTC', '001', ['item_id' => '001']);
    $second = storeEvent($metric->organization, $subscription, '2023-03-17 12:00:00 UTC', '001', ['item_id' => '001']);

    // The previous event for `second` is the initial add → still active.
    expect($store->activeUniqueProperty($second))->toBeTrue();

    storeEvent($metric->organization, $subscription, '2023-03-18 12:00:00 UTC', '001', ['item_id' => '001', 'operation_type' => 'remove']);
    $reAdd = storeEvent($metric->organization, $subscription, '2023-03-19 12:00:00 UTC', '001', ['item_id' => '001']);

    // The previous event for `reAdd` is the remove → NOT active (the
    // re-add is the one that counts), and the first event has no previous.
    expect($store->activeUniqueProperty($reAdd))->toBeFalse()
        ->and($store->activeUniqueProperty($first))->toBeFalse();
});
