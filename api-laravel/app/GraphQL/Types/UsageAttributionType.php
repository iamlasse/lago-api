<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\UsageAttributionType as UsageAttributionTypeModel;

/**
 * Field resolvers for the frozen SDL's `UsageAttributionType` type (port of
 * Rails' Types::UsageAttributionTypes::Object) — the pg-enum role resolves
 * to its wire name, and the tree relations resolve through the model.
 */
class UsageAttributionType
{
    /** Rails: field :role, UsageAttributionTypeRoleEnum — the pg enum name. */
    public function role(UsageAttributionTypeModel $root): string
    {
        return $root->roleName();
    }

    /** Rails: field :attribution_keys, [String]. */
    public function attributionKeys(UsageAttributionTypeModel $root): array
    {
        return array_values((array) ($root->attribution_keys ?? []));
    }

    /** Rails: field :parent (with_discarded). */
    public function parent(UsageAttributionTypeModel $root): ?UsageAttributionTypeModel
    {
        return $root->parent;
    }

    /** Rails: field :children, -> { order(:code) }. */
    public function children(UsageAttributionTypeModel $root): iterable
    {
        return $root->children;
    }

    /** Rails: field :organization. */
    public function organization(UsageAttributionTypeModel $root): \App\Models\Organization
    {
        return $root->organization;
    }
}
