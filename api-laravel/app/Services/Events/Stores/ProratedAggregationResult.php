<?php

declare(strict_types=1);

namespace App\Services\Events\Stores;

/**
 * Port of Rails' Events::Stores::BaseStore::ProratedAggregationResult —
 * unlike AggregationResult it also carries the raw, non-prorated value
 * alongside the events count.
 */
final class ProratedAggregationResult
{
    public function __construct(
        public readonly string|int $value,
        public readonly string|int $proratedValue,
        public readonly string|int|null $eventsCount,
    ) {}
}
