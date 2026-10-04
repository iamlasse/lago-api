<?php

declare(strict_types=1);

namespace App\Services\Events\Stores;

/**
 * Port of Rails' Events::Stores::BaseStore::GroupedAggregationResult —
 * mirrors AggregationResult but also carries the group it belongs to.
 */
final class GroupedAggregationResult
{
    /**
     * @param  array<string, mixed>  $groups
     */
    public function __construct(
        public readonly array $groups,
        public readonly string|int $value,
        public readonly string|int|null $eventsCount,
    ) {}

    /** Rails: `#to_grouped_hash`. */
    public function toGroupedHash(): array
    {
        return ['groups' => $this->groups, 'value' => $this->value];
    }
}
