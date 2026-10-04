<?php

declare(strict_types=1);

namespace App\Services\Events\Stores;

/**
 * Port of Rails' Events::Stores::BaseStore::GroupedWeightedAggregationResult.
 */
final class GroupedWeightedAggregationResult
{
    /**
     * @param  array<string, mixed>  $groups
     */
    public function __construct(
        public readonly array $groups,
        public readonly string|int $value,
        public readonly string|int $variation,
        public readonly int $eventsCount,
    ) {}

    /** Rails: `#to_grouped_hash`. */
    public function toGroupedHash(): array
    {
        return ['groups' => $this->groups, 'value' => $this->value];
    }
}
