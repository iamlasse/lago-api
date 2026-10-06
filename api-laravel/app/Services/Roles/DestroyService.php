<?php

declare(strict_types=1);

namespace App\Services\Roles;

use App\Models\Role;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\MembershipRole;

/**
 * Port of Rails' Roles::DestroyService
 * (app/services/roles/destroy_service.rb) — a soft delete (discard!) that
 * refuses predefined roles and roles still carried by active memberships.
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): Utils::SecurityLog.produce("role.deleted").
 */
class DestroyService extends BaseService
{
    public function __construct(
        private readonly ?Role $role,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('role');

        if ($this->role === null) {
            return $result->notFoundFailure('role');
        }

        if ($this->role->organization_id === null) {
            return $result->forbiddenFailure('predefined_role');
        }

        // Rails: role.active_memberships.exists? — memberships (active)
        // joined through the kept pivot rows.
        $assigned = MembershipRole::query()
            ->whereNull('membership_roles.deleted_at')
            ->join('memberships', 'memberships.id', '=', 'membership_roles.membership_id')
            ->where('membership_roles.role_id', $this->role->id)
            ->where('memberships.status', 0)

            ->exists();

        if ($assigned) {
            return $result->forbiddenFailure('role_assigned_to_members');
        }

        $this->role->delete(); // SoftDeletes → discard!

        $result->role = $this->role;

        return $result;
    }
}
