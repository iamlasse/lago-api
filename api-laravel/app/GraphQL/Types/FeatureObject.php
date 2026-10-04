<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Feature;

/**
 * Field resolvers for the frozen SDL's `FeatureObject` type (port of
 * Rails' Types::Entitlement::FeatureObject). Plain columns resolve through
 * the snake_case attribute fallback; the privileges and subscriptionsCount
 * fields need methods.
 */
class FeatureObject
{
    /** Rails: `object.privileges` — the feature's privileges. */
    public function privileges(Feature $root): array
    {
        return $root->privileges->all();
    }

    /** Rails: `object.subscriptions_count` — preloaded by the resolver. */
    public function subscriptionsCount(Feature $root): int
    {
        return $root->subscriptionsCount();
    }
}
