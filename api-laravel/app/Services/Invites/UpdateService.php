<?php

declare(strict_types=1);

namespace App\Services\Invites;

use App\Models\Role;
use App\Models\User;
use App\Models\Invite;
use App\Enums\InviteStatus;
use App\Services\BaseResult;
use App\Services\BaseService;

/**
 * Port of Rails' Invites::UpdateService
 * (app/services/invites/update_service.rb).
 */
class UpdateService extends BaseService
{
    public function __construct(
        private readonly ?User $user,
        private readonly ?Invite $invite,
        /** @var array<string, mixed> */
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('invite');

        if ($this->invite === null) {
            return $result->notFoundFailure('invite');
        }

        if ($this->invite->status === InviteStatus::Accepted) {
            return $result->forbiddenFailure('cannot_update_accepted_invite');
        }

        if ($this->invite->status === InviteStatus::Revoked) {
            return $result->forbiddenFailure('cannot_update_revoked_invite');
        }

        if ($this->grantingAdminWithoutBeingAdmin()) {
            return $result->forbiddenFailure('cannot_grant_admin');
        }

        if (! $this->validRoles()) {
            return $result->singleValidationFailure('invalid_role', 'roles');
        }

        $this->invite->roles = $this->params['roles'] ?? null;
        $this->invite->save();

        $result->invite = $this->invite;

        return $result;
    }

    /**
     * Rails: `granting_admin_without_being_admin?` — the acting membership
     * is resolved against the invite's organization.
     */
    private function grantingAdminWithoutBeingAdmin(): bool
    {
        if (! in_array('admin', (array) ($this->params['roles'] ?? []), true)) {
            return false;
        }

        $membership = $this->invite->organization->activeMemberships()
            ->where('user_id', $this->user?->id)
            ->first();

        if ($membership === null) {
            return true;
        }

        return ! $membership->roles()->where('roles.admin', true)->exists();
    }

    /**
     * Rails: `valid_roles?` — every role code must exist for the invite's
     * organization; a miss answers single_validation_failure(roles,
     * invalid_role).
     */
    private function validRoles(): bool
    {
        $roles = $this->params['roles'] ?? null;

        if ($roles === null || (is_array($roles) && $roles === [])) {
            return false;
        }

        $found = Role::query()
            ->whereIn('code', (array) $roles)
            ->where(function ($query): void {
                $query->whereNull('organization_id')
                    ->orWhere('organization_id', $this->invite->organization_id);
            })
            ->pluck('code')
            ->all();

        return array_diff((array) $roles, $found) === [];
    }
}
