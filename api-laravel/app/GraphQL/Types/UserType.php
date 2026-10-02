<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\User;
use App\Enums\MembershipStatus;

/**
 * Field resolvers for the partial `User` type (port of Rails'
 * app/graphql/types/user_type.rb).
 */
class UserType
{
    /**
     * Rails: object.memberships.active.includes(:organization)
     * (Rails enum :status, [:active, :revoked] → active == 0).
     */
    public function memberships(User $root): iterable
    {
        return $root->memberships()
            ->where('status', MembershipStatus::Active)
            ->with('organization')
            ->get();
    }

    /** Rails: object.organizations = memberships.map(&:organization). */
    public function organizations(User $root): iterable
    {
        return collect($this->memberships($root))->map->organization->values();
    }

    /**
     * Rails: License.premium? (lago_premium gem; false without a premium
     * license). The license port lands with its own ledger row.
     */
    public function premium(User $root): bool
    {
        return false;
    }
}
