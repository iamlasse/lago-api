<?php

declare(strict_types=1);

namespace App\Services\Memberships;

use App\Models\User;
use App\Models\Membership;
use App\Services\BaseResult;
use App\Services\BaseService;

/**
 * Port of Rails' Memberships::RevokeService
 * (app/services/memberships/revoke_service.rb).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): Utils::SecurityLog.produce("user.deleted").
 */
class RevokeService extends BaseService
{
    public function __construct(
        private readonly ?User $user,
        private readonly ?Membership $membership,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('membership');

        if ($this->membership === null) {
            return $result->notFoundFailure('membership');
        }

        if ($this->user !== null && $this->user->id === $this->membership->user_id) {
            return $result->notAllowedFailure('cannot_revoke_own_membership');
        }

        // Rails: membership.admin? && organization.admin_membership_roles
        // .count == 1 — the last admin cannot be revoked. The port counts
        // the kept membership_roles of active admin-carrying memberships.
        if ($this->isAdmin($this->membership) && $this->adminMembershipRolesCount() === 1) {
            return $result->notAllowedFailure('last_admin');
        }

        $this->membership->markAsRevoked();

        $result->membership = $this->membership;

        return $result;
    }

    /** Rails: membership.admin? — roles.admins.exists?. */
    private function isAdmin(Membership $membership): bool
    {
        return $membership->roles()->where('roles.admin', true)->exists();
    }

    /**
     * Rails: organization.admin_membership_roles.count — membership_roles
     * of the org's active memberships whose role is admin.
     */
    private function adminMembershipRolesCount(): int
    {
        return \App\Models\MembershipRole::query()
            ->whereNull('membership_roles.deleted_at')
            ->join('memberships', 'memberships.id', '=', 'membership_roles.membership_id')
            ->join('roles', 'roles.id', '=', 'membership_roles.role_id')
            ->where('memberships.organization_id', $this->membership->organization_id)
            ->where('memberships.status', 0)
            
            ->where('roles.admin', true)
            ->whereNull('roles.deleted_at')
            ->count();
    }
}
