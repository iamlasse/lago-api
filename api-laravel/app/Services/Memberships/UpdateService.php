<?php

declare(strict_types=1);

namespace App\Services\Memberships;

use App\Models\Role;
use App\Models\User;
use App\Models\Membership;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\MembershipRole;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' Memberships::UpdateService
 * (app/services/memberships/update_service.rb) — the membership_roles pivot
 * is synced transactionally (added roles inserted, removed ones discarded).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): Utils::SecurityLog.produce("user.role_edited").
 */
class UpdateService extends BaseService
{
    public function __construct(
        private readonly ?User $user,
        private readonly ?Membership $membership,
        /** @var array<string, mixed> */
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('membership');

        $newRoles = $this->newRoles();

        if ($this->membership === null) {
            return $result->notFoundFailure('membership');
        }

        if ($newRoles->isEmpty()) {
            return $result->notFoundFailure('role');
        }

        if ($this->grantingAdminWithoutBeingAdmin($newRoles)) {
            return $result->forbiddenFailure('cannot_grant_admin');
        }

        if ($this->lastAdminDemotion($newRoles)) {
            return $result->notAllowedFailure('last_admin');
        }

        DB::transaction(function () use ($newRoles): void {
            $oldRoles = $this->membership->roles()->get();

            $oldIds = $oldRoles->pluck('id')->all();
            $newIds = $newRoles->pluck('id')->all();

            foreach ($newIds as $roleId) {
                if (in_array($roleId, $oldIds, true)) {
                    continue;
                }

                // Rails: MembershipRole.create! — also revive a discarded
                // pivot row (Rails would create a new row; the unique index
                // is per organization+membership+role without a discard
                // predicate, so the port restores instead).
                MembershipRole::withTrashed()->updateOrCreate(
                    [
                        'organization_id' => $this->membership->organization_id,
                        'membership_id' => $this->membership->id,
                        'role_id' => $roleId,
                    ],
                    ['deleted_at' => null],
                );
            }

            $toRemove = array_diff($oldIds, $newIds);

            if ($toRemove !== []) {
                MembershipRole::query()
                    ->where('membership_id', $this->membership->id)
                    ->whereIn('role_id', $toRemove)
                    ->delete();
            }
        });

        $result->membership = $this->membership->refresh();

        return $result;
    }

    /**
     * Rails: Role.with_code(*params[:roles]).with_organization(
     * membership.organization_id) — unknown codes never match.
     *
     * @param  \Illuminate\Support\Collection<int, Role>  $newRoles
     */
    private function grantingAdminWithoutBeingAdmin($newRoles): bool
    {
        if ($newRoles->doesntContain(fn (Role $role): bool => (bool) $role->admin)) {
            return false;
        }

        $membership = $this->membership->organization->activeMemberships()
            ->where('user_id', $this->user?->id)
            ->first();

        if ($membership === null) {
            return true;
        }

        return ! $membership->roles()->where('roles.admin', true)->exists();
    }

    /**
     * Rails: last_admin_demotion? — the membership is admin, none of the
     * new roles are, and the org carries exactly one admin membership role.
     *
     * @param  \Illuminate\Support\Collection<int, Role>  $newRoles
     */
    private function lastAdminDemotion($newRoles): bool
    {
        if (! $this->membership->roles()->where('roles.admin', true)->exists()) {
            return false;
        }

        if ($newRoles->contains(fn (Role $role): bool => (bool) $role->admin)) {
            return false;
        }

        return MembershipRole::query()
            ->whereNull('membership_roles.deleted_at')
            ->join('memberships', 'memberships.id', '=', 'membership_roles.membership_id')
            ->join('roles', 'roles.id', '=', 'membership_roles.role_id')
            ->where('memberships.organization_id', $this->membership->organization_id)
            ->where('memberships.status', 0)
            ->where('roles.admin', true)
            ->whereNull('roles.deleted_at')
            ->count() === 1;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Role>
     */
    private function newRoles()
    {
        $codes = array_values(array_filter((array) ($this->params['roles'] ?? []), is_string(...)));

        if ($codes === []) {
            // Rails: Role.none when the codes are blank.
            return Role::query()->whereRaw('1 = 0')->get();
        }

        return Role::query()
            ->whereIn('code', $codes)
            ->where(function ($query): void {
                $query->whereNull('organization_id')
                    ->orWhere('organization_id', $this->membership->organization_id);
            })
            ->get();
    }
}
