<?php

declare(strict_types=1);

namespace App\Services\Events\Stores;

/**
 * Port of Rails' Events::Stores::BaseStore::GroupedProratedAggregationResult.
 */
final class GroupedProratedAggregationResult
{
    /**
     * @param  array<string, mixed>  $groups
     */
    public function __construct(
        public readonly array $groups,
        public readonly string|int $value,
        public readonly string|int $proratedValue,
        public readonly string|int|null $eventsCount,
    ) {}
}
