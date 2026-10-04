<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Organization;
use App\Models\Subscription;
use Illuminate\Support\Facades\Http;
use App\Services\Events\Stores\StoreFactory;
use App\Services\Events\Stores\ClickHouseStore;
use App\Models\Billing\Context as BillingContext;

/**
 * SQL-shape coverage of the ported Events::Stores::ClickhouseStore — the
 * exact statements the store POSTs to the ClickHouse HTTP interface are
 * asserted against the Rails query builders' output shape (FINAL dedup
 * reads, the events/events_enriched CTE pair, lagInFrame windows,
 * toDecimal casts, the group aliases). These run WITHOUT a ClickHouse
 * container (Http::fake); the real-container smoke test at the bottom
 * requires LAGO_CLICKHOUSE_TEST_HOST.
 */
uses()->group('ledger:svc:Events.Stores.ClickHouseStore');

function chOrganization(): Organization
{
    return Organization::factory()->create();
}

function chCustomer(Organization $organization): Customer
{
    return Customer::factory()->create([
        'organization_id' => $organization->id,
        'timezone' => 'UTC',
    ]);
}

function chSubscription(Customer $customer): Subscription
{
    return Subscription::factory()->create([
        'organization_id' => $customer->organization_id,
        'customer_id' => $customer->id,
        'started_at' => Carbon\CarbonImmutable::parse('2023-03-15 00:00:00 UTC'),
    ]);
}

function chStore(Subscription $subscription, array $boundaries = [], array $filters = [], bool $deduplicate = false): ClickHouseStore
{
    $store = new ClickHouseStore(
        billingContext: BillingContext::fromSubscription($subscription),
        boundaries: $boundaries ?: [
            'from_datetime' => Carbon\CarbonImmutable::parse('2023-03-15 00:00:00 UTC'),
            'to_datetime' => Carbon\CarbonImmutable::parse('2023-03-31 23:59:59.999999 UTC'),
            'charges_duration' => 31,
        ],
        code: 'bm:code',
        filters: $filters,
        deduplicate: $deduplicate,
    );

    $store->setAggregationProperty('value');
    $store->setNumericProperty(true);

    return $store;
}

function chFake(): void
{
    // FORMAT JSON rows: every column is a string, like the
    // clickhouse-activerecord adapter surfaces them.
    Http::fake(['*' => Http::response(['data' => []], 200)]);
}

function chBodies(): array
{
    return collect(Http::recorded())
        ->map(fn (array $pair): string => (string) $pair[0]->body())
        ->values()
        ->all();
}

it('posts the plain (non deduplicated) events CTE for count', function (): void {
    chFake();
    $store = chStore(chSubscription(chCustomer(chOrganization())));

    $store->count();

    $body = chBodies()[0];

    expect($body)
        ->toContain('FROM events_enriched WHERE')
        ->toContain('external_subscription_id = ')
        ->toContain('organization_id = ')
        ->toContain("code = 'bm:code'")
        ->toContain('timestamp >= \'2023-03-15 00:00:00.000\'')
        ->toContain('timestamp <= \'2023-03-31 23:59:59.999\'')
        ->toContain('SELECT count() FROM events');
});

it('reads the FINAL dedup table directly for an unfiltered deduplicated count', function (): void {
    chFake();
    $store = chStore(chSubscription(chCustomer(chOrganization())), deduplicate: true);

    $store->count();

    $body = chBodies()[0];

    // No events CTE is needed: the count only needs the number of distinct
    // dedup keys, read straight off events_enriched FINAL.
    expect($body)
        ->toContain('SELECT count() FROM events_enriched FINAL WHERE')
        ->toContain('organization_id = ')
        ->toContain('AND code = ')
        ->toContain('AND external_subscription_id = ')
        ->not->toContain('WITH events_enriched AS');
});

it('sums over the deduplicated events CTE pair', function (): void {
    chFake();
    $store = chStore(chSubscription(chCustomer(chOrganization())), deduplicate: true);

    $store->sum();

    $body = chBodies()[0];

    expect($body)
        ->toContain('WITH events_enriched AS (')
        ->toContain('FROM events_enriched FINAL WHERE')
        // The deduplicated column set: the key + decimal_value.
        ->toContain(', decimal_value')
        ->toContain('), events AS (SELECT * FROM events_enriched)')
        ->toContain('SELECT sum(events.decimal_value) as value, count() as events_count FROM events');
});

it('honors the pay-in-advance upper boundary tie-break by transaction id', function (): void {
    chFake();

    $organization = chOrganization();
    $subscription = chSubscription(chCustomer($organization));

    $event = App\Models\Event::factory()->create([
        'organization_id' => $organization->id,
        'external_subscription_id' => $subscription->external_id,
        'transaction_id' => 'txn_boundary',
        'code' => 'bm:code',
    ]);

    $to = Carbon\CarbonImmutable::parse('2023-03-20 12:00:00 UTC');

    $store = new ClickHouseStore(
        billingContext: BillingContext::fromSubscription($subscription),
        boundaries: [
            'from_datetime' => Carbon\CarbonImmutable::parse('2023-03-15 00:00:00 UTC'),
            'to_datetime' => $to,
            'max_timestamp' => $to,
            'charges_duration' => 31,
        ],
        code: 'bm:code',
        filters: ['event' => $event],
    );
    $store->setAggregationProperty('value');

    $store->count();

    expect(chBodies()[0])
        ->toContain('timestamp < \'2023-03-20 12:00:00.000\'')
        ->toContain("OR (events_enriched.timestamp = '2023-03-20 12:00:00.000' AND events_enriched.transaction_id <= 'txn_boundary')");
});

it('embeds the charge filter properties into the deduplicated column set', function (): void {
    chFake();
    $store = chStore(
        chSubscription(chCustomer(chOrganization())),
        filters: ['matching_filters' => ['region' => ['europe', 'asia']]],
        deduplicate: true,
    );

    $store->sum();

    $body = chBodies()[0];

    // Grouping and filtering are made based on the properties, so the
    // properties column joins the deduplicated columns.
    expect($body)
        ->toContain(', properties')
        ->toContain("events_enriched.properties['region'] IN ('europe', 'asia')")
        ->toContain('FROM events_enriched FINAL WHERE');
});

it('groups sums by the g_0 aliases over the properties', function (): void {
    chFake();
    $store = chStore(
        chSubscription(chCustomer(chOrganization())),
        filters: ['grouped_by' => ['region']],
        deduplicate: true,
    );

    $store->groupedSum();

    $body = chBodies()[0];

    expect($body)
        ->toContain("events_enriched.properties['region'] AS g_0")
        ->toContain('SELECT g_0, sum(events.property), count() FROM events GROUP BY g_0')
        ->toContain('events_enriched.decimal_value AS property');
});

it('builds the weighted sum events_data CTE with the boundary rows', function (): void {
    chFake();
    $store = chStore(chSubscription(chCustomer(chOrganization())));

    $store->weightedSum(10);

    $body = chBodies()[0];

    expect($body)
        // The events CTE + the boundary rows all merge into one events_data CTE.
        ->toContain('events_data AS (')
        // Initial value row…
        ->toContain("toDateTime64('2023-03-15 00:00:00.000', 5, 'UTC') as timestamp")
        ->toContain("toDecimal128('10.0', 26) as difference")
        // …UNION ALL events UNION ALL the end-of-period zero row.
        ->toContain('UNION ALL')
        ->toContain("toDateTime64('2023-04-01 00:00:00.000', 5, 'UTC') as timestamp")
        ->toContain("leadInFrame(timestamp, 1, toDateTime64('2023-04-01 00:00:00.000', 5, 'UTC'))")
        ->toContain('2678400') // 31 days in seconds
        ->toContain('sum(period_ratio) as aggregation');
});

it('builds the unique count query with the lagInFrame window', function (): void {
    chFake();
    $store = chStore(chSubscription(chCustomer(chOrganization())));

    $store->uniqueCount();

    $body = chBodies()[0];

    expect($body)
        ->toContain("coalesce(nullif(events_enriched.sorted_properties['operation_type'], ''), 'add') AS operation_type")
        ->toContain('lagInFrame(operation_type, 1) OVER (PARTITION BY property ORDER BY timestamp ROWS BETWEEN 1 PRECEDING AND 1 PRECEDING)')
        ->toContain('toDecimal32(-1, 0)')
        ->toContain('SELECT coalesce(SUM(sum_adjusted_value), 0) AS aggregation FROM event_values');
});

it('builds the prorated unique count with the same-day remove filter', function (): void {
    chFake();
    $store = chStore(chSubscription(chCustomer(chOrganization())));

    $store->proratedUniqueCount();

    $body = chBodies()[0];

    expect($body)
        ->toContain('same_day_ignored AS (')
        ->toContain('MAX(timestamp) OVER (PARTITION BY property, toDate(timestamp, \'UTC\')) AS is_last_event_of_day')
        ->toContain("WHEN operation_type = 'remove' AND NOT is_last_event_of_day THEN true")
        ->toContain("leadInFrame(timestamp, 1, toDateTime64('2023-03-31 23:59:59.999', 3, 'UTC'))")
        ->toContain('/ 31');
});

it('returns a real aggregation from a faked FORMAT JSON payload', function (): void {
    Http::fake(['*' => Http::response([
        'data' => [['value' => '12.5', 'events_count' => '3']],
    ], 200)]);

    $result = chStore(chSubscription(chCustomer(chOrganization())))->sum();

    expect($result->value)->toBe('12.5')
        ->and($result->eventsCount)->toBe(3);
});

it('selects the clickhouse store via the factory when the env flag and org flag are set', function (): void {
    config(['lago.clickhouse.enabled' => 'true']);

    $organization = chOrganization();
    $organization->clickhouse_events_store = true;
    $organization->save();

    expect(StoreFactory::supportsClickhouse())->toBeTrue()
        ->and(StoreFactory::storeClass($organization))->toBe(ClickHouseStore::class);

    config(['lago.clickhouse.enabled' => null]);

    expect(StoreFactory::supportsClickhouse())->toBeFalse()
        ->and(StoreFactory::storeClass($organization))->toBe(App\Services\Events\Stores\PostgresStore::class);
});

// -- Real-container smoke (skipped without LAGO_CLICKHOUSE_TEST_HOST) -------

it('runs the deduplicated count against a real ClickHouse container', function (): void {
    $host = getenv('LAGO_CLICKHOUSE_TEST_HOST');

    if ($host === false || $host === '') {
        $this->markTestSkipped('Set LAGO_CLICKHOUSE_TEST_HOST (and run scripts/clickhouse/init.sql) for the real-container smoke.');
    }

    config([
        'lago.clickhouse.enabled' => 'true',
        'lago.clickhouse.host' => $host,
        'lago.clickhouse.port' => (int) (getenv('LAGO_CLICKHOUSE_TEST_PORT') ?: 8123),
        'lago.clickhouse.database' => getenv('LAGO_CLICKHOUSE_TEST_DATABASE') ?: 'default',
        'lago.clickhouse.username' => getenv('LAGO_CLICKHOUSE_TEST_USERNAME') ?: 'default',
        'lago.clickhouse.password' => getenv('LAGO_CLICKHOUSE_TEST_PASSWORD') ?: '',
    ]);

    $organization = chOrganization();
    $subscription = chSubscription(chCustomer($organization));

    $client = new App\Services\ClickHouse\Client;

    // Seed two events; the second is a RE-ENRICHMENT of the first (same
    // dedup key, later timestamp) so the FINAL read must collapse them.
    $rows = [
        [
            'organization_id' => $organization->id,
            'external_subscription_id' => $subscription->external_id,
            'code' => 'bm:code',
            'timestamp' => '2026-10-01 10:00:00.000',
            'transaction_id' => 'txn_live_1',
            'properties' => ['region' => 'europe'],
            'value' => '5',
        ],
        [
            'organization_id' => $organization->id,
            'external_subscription_id' => $subscription->external_id,
            'code' => 'bm:code',
            'timestamp' => '2026-10-01 10:00:00.000',
            'transaction_id' => 'txn_live_1',
            'properties' => ['region' => 'europe'],
            'value' => '9',
        ],
        [
            'organization_id' => $organization->id,
            'external_subscription_id' => $subscription->external_id,
            'code' => 'bm:code',
            'timestamp' => '2026-10-02 10:00:00.000',
            'transaction_id' => 'txn_live_2',
            'properties' => ['region' => 'asia'],
            'value' => '3',
        ],
    ];

    $client->execute(
        'INSERT INTO events_enriched ('.implode(', ', array_keys($rows[0])).') FORMAT JSONEachRow '
            .implode("\n", array_map(static fn (array $row): string => json_encode($row), $rows)),
    );

    $store = new ClickHouseStore(
        billingContext: BillingContext::fromSubscription($subscription),
        boundaries: [
            'from_datetime' => Carbon\CarbonImmutable::parse('2026-10-01 00:00:00 UTC'),
            'to_datetime' => Carbon\CarbonImmutable::parse('2026-10-31 23:59:59.999999 UTC'),
            'charges_duration' => 31,
        ],
        code: 'bm:code',
        deduplicate: true,
    );
    $store->setAggregationProperty('value');
    $store->setNumericProperty(true);

    // FINAL collapses the re-enrichment: 2 events, sum 9 + 3 = 12.
    $count = $store->count();
    $sum = $store->sum();

    expect((int) $count->value)->toBe(2)
        ->and((float) $sum->value)->toBe(12.0)
        ->and((int) $sum->eventsCount)->toBe(2);

    $grouped = $store->groupedSum(['region']);

    expect($grouped)->toHaveCount(2);

    $byRegion = [];
    foreach ($grouped as $group) {
        $byRegion[$group->groups['region']] = (float) $group->value;
    }

    expect($byRegion['europe'])->toBe(9.0)
        ->and($byRegion['asia'])->toBe(3.0);
})->group('clickhouse-live');
