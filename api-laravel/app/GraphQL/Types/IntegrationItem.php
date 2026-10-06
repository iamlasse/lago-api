<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\IntegrationItem as IntegrationItemModel;

/**
 * Field resolvers for the frozen SDL's `IntegrationItem` type (port of
 * Rails' Types::IntegrationItems::Object) — `itemType` serializes the Rails
 * enum name, not the raw integer column.
 */
class IntegrationItem
{
    public function itemType(IntegrationItemModel $root): ?string
    {
        $raw = $root->getRawOriginal('item_type');

        // Rails: enum :item_type, [:standard, :tax, :account].
        return match ($raw === null ? null : (int) $raw) {
            0 => 'standard',
            1 => 'tax',
            2 => 'account',
            default => null,
        };
    }
}
