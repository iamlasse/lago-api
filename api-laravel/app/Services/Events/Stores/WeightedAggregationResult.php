<?php

declare(strict_types=1);

namespace App\Services\Events\Stores;

/**
 * Port of Rails' Events::Stores::BaseStore::WeightedAggregationResult —
 * unlike AggregationResult it also carries the variation (sum of the real
 * events, initial_value already subtracted) alongside the weighted value.
 */
final class WeightedAggregationResult
{
    public function __construct(
        public readonly string|int $value,
        public readonly string|int $variation,
        public readonly int $eventsCount,
    ) {}
}
