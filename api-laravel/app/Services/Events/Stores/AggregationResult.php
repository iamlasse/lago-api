<?php

declare(strict_types=1);

namespace App\Services\Events\Stores;

/**
 * Port of Rails' Events::Stores::BaseStore::AggregationResult
 * (base_store.rb) — the raw store-level aggregate: the value plus the
 * number of events that produced it. Values are decimal strings exactly as
 * Postgres' numeric driver returns them (never floats).
 */
final class AggregationResult
{
    public function __construct(
        public readonly string|int|null $value,
        public readonly string|int|null $eventsCount,
    ) {}
}
