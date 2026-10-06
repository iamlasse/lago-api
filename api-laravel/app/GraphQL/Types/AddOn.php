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
     * Rails: object.integration_mappings (optionally narrowed by
     * integration_id) — the add-on's mappings over the frozen
     * integration_mappings table.
     */
    public function integrationMappings(AddOnModel $root, array $args = []): ?array
    {
        $query = \App\Models\IntegrationMappings\BaseMapping::query()
            ->where('mappable_type', 'AddOn')
            ->where('mappable_id', $root->id);

        if (($args['integrationId'] ?? null) !== null) {
            $query->where('integration_id', $args['integrationId']);
        }

        return $query->get()->all();
    }
}
