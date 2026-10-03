<?php

declare(strict_types=1);

namespace App\Services\Events\Stores;

use RuntimeException;
use App\Models\Organization;

/**
 * Port of Rails' Events::Stores::StoreFactory
 * (app/services/events/stores/store_factory.rb): picks the events store
 * class — ClickHouse when LAGO_CLICKHOUSE_ENABLED is set AND the
 * organization opted into the clickhouse events store, Postgres otherwise.
 *
 * TODO(port): clickhouse store — LAGO_CLICKHOUSE_ENABLED is not set in this
 * environment, so the PostgresStore path is the active one; the
 * ClickHouseStore is a throwing stub (see its docblock).
 */
class StoreFactory
{
    /** @var array{store_class: class-string<BaseStore>, deduplicate: bool}|null */
    private static ?array $override = null;

    final public function __construct() {}

    /**
     * Port of `ENV["LAGO_CLICKHOUSE_ENABLED"].present?`.
     */
    public static function supportsClickhouse(): bool
    {
        $value = getenv('LAGO_CLICKHOUSE_ENABLED');

        return $value !== false && $value !== '';
    }

    /**
     * Port of `store_class(organization:)` — the override wins, then the
     * environment/organization decision.
     *
     * @return class-string<BaseStore>
     */
    public static function storeClass(Organization $organization): string
    {
        $override = self::override();
        if ($override !== null) {
            return $override['store_class'];
        }

        if (self::supportsClickhouse() && $organization->clickhouseEventsStore()) {
            return ClickHouseStore::class;
        }

        return PostgresStore::class;
    }

    /**
     * Port of `new_instance(organization:, billing_context:, **kwargs)`.
     *
     * @param  array<string, mixed>  $kwargs
     */
    public static function newInstance(Organization $organization, mixed $billingContext, array $kwargs = []): BaseStore
    {
        $storeClass = self::storeClass($organization);

        return new $storeClass(...['billingContext' => $billingContext, ...$kwargs]);
    }

    /**
     * Port of `with_override(store_class:, deduplicate:)` — temporarily
     * overrides the resolved store class and deduplication flag for the
     * duration of the callback (tooling that must exercise a different event
     * store without persisting state on the organization).
     *
     * @template T
     *
     * @param  class-string<BaseStore>  $storeClass
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withOverride(string $storeClass, bool $deduplicate, callable $callback): mixed
    {
        if (self::override() !== null) {
            throw new RuntimeException('Events::Stores::StoreFactory override already active');
        }

        self::$override = ['store_class' => $storeClass, 'deduplicate' => $deduplicate];

        try {
            return $callback();
        } finally {
            self::$override = null;
        }
    }

    /** @return array{store_class: class-string<BaseStore>, deduplicate: bool}|null */
    private static function override(): ?array
    {
        return self::$override;
    }
}
