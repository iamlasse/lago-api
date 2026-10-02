<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Membership;

/**
 * Field resolvers for the partial `Membership` type (port of Rails'
 * app/graphql/types/membership_type.rb).
 */
class MembershipType
{
    /**
     * Rails serializes the integer status enum as its Rails name
     * (`active` / `revoked`) — the frozen schema's MembershipStatus values.
     */
    public function status(Membership $root): string
    {
        return $root->status->label();
    }
}
