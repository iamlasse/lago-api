<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Services\Events\Stores\BaseStore;
use App\Services\Events\Stores\StoreFactory;
use App\Services\Events\Stores\PostgresStore;
use App\Services\Events\Stores\ClickHouseStore;

uses()->group('ledger:svc:Events.Stores.StoreFactory');

/**
 * Port of Rails' spec for Events::Stores::StoreFactory's store decision.
 */
function storeFactoryOrganization(bool $clickhouse = false): Organization
{
    return Organization::factory()->create(['clickhouse_events_store' => $clickhouse]);
}

it('resolves the postgres store without the clickhouse env flag', function (): void {
    expect(StoreFactory::supportsClickhouse())->toBeFalse()
        ->and(StoreFactory::storeClass(storeFactoryOrganization()))->toBe(PostgresStore::class)
        ->and(StoreFactory::storeClass(storeFactoryOrganization(clickhouse: true)))->toBe(PostgresStore::class);
});

it('resolves the clickhouse store when the flag and the organization opt in', function (): void {
    putenv('LAGO_CLICKHOUSE_ENABLED=true');

    try {
        expect(StoreFactory::supportsClickhouse())->toBeTrue()
            ->and(StoreFactory::storeClass(storeFactoryOrganization(clickhouse: true)))->toBe(ClickHouseStore::class)
            // The organization flag decides — a postgres org stays on postgres.
            ->and(StoreFactory::storeClass(storeFactoryOrganization()))->toBe(PostgresStore::class);
    } finally {
        putenv('LAGO_CLICKHOUSE_ENABLED');
    }
});

it('overrides the resolved store for the duration of a block', function (): void {
    $organization = storeFactoryOrganization();

    $inside = StoreFactory::withOverride(ClickHouseStore::class, true, function () use ($organization): string {
        return StoreFactory::storeClass($organization);
    });

    expect($inside)->toBe(ClickHouseStore::class)
        ->and(StoreFactory::storeClass($organization))->toBe(PostgresStore::class);
});

it('refuses a nested override', function (): void {
    storeFactoryOrganization();

    StoreFactory::withOverride(ClickHouseStore::class, true, function (): void {
        StoreFactory::withOverride(PostgresStore::class, false, function (): void {});
    });
})->throws(RuntimeException::class, 'Events::Stores::StoreFactory override already active');

it('instantiates the (now ported) clickhouse store under an override', function (): void {
    $organization = storeFactoryOrganization();

    StoreFactory::withOverride(ClickHouseStore::class, false, function () use ($organization): void {
        $store = StoreFactory::newInstance($organization, billingContext: new stdClass());

        // The store is implemented (aggregations POST to the ClickHouse
        // HTTP interface — see ClickHouseStoreTest for the SQL shapes);
        // without a configured server a call surfaces the client's
        // connection error rather than a stub throw.
        expect($store)->toBeInstanceOf(ClickHouseStore::class)
            ->and(fn (): mixed => $store->count())->toThrow(Error::class);
    });
})->group('clickhouse-store');

it('resolves the postgres store with a live aggregation API (finding 12)', function (): void {
    $store = StoreFactory::newInstance(storeFactoryOrganization(), billingContext: new stdClass());

    expect($store)->toBeInstanceOf(PostgresStore::class)
        ->and($store)->toBeInstanceOf(BaseStore::class);
});
