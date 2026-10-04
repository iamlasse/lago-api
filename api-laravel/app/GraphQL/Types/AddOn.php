<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\AddOn as AddOnModel;

/**
 * Field resolvers for the frozen SDL's `AddOn` type (port of Rails'
 * Types::AddOn computed fields). Plain columns resolve through the
 * snake_case attribute fallback; taxes resolve through the relation.
 */
class AddOn
{
    /** Rails: applied_add_ons.count — every applied add-on. */
    public function appliedAddOnsCount(AddOnModel $root): int
    {
        return $root->appliedAddOns()->count();
    }

    /** Rails: applied_add_ons.select(:customer_id).distinct.count. */
    public function customersCount(AddOnModel $root): int
    {
        return $root->appliedAddOns()
            ->select('applied_add_ons.customer_id')
            ->distinct()
            ->count('applied_add_ons.customer_id');
    }

    /**
     * Rails: object.integration_mappings — the integration mappings are a
     * later milestone (stubs, see FULL_SCHEMA_NOTES.md).
     */
    public function integrationMappings(AddOnModel $root): ?array
    {
        return null;
    }
}
