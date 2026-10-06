<?php

declare(strict_types=1);

namespace App\Services\Invites;

use App\Models\Role;
use App\Models\User;
use App\Models\Invite;
use App\Models\Membership;
use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;

/**
 * Port of Rails' Invites::CreateService
 * (app/services/invites/create_service.rb) + Invites::ValidateService
 * (app/services/invites/validate_service.rb).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): Utils::SecurityLog.produce("user.invited").
 * - TODO(port): the InviteMailer invitation email (mailer infra).
 */
class CreateService extends BaseService
{
    public function __construct(
        private readonly Organization $current_organization,
        private readonly ?User $user,
        private readonly ?string $email,
        /** @var list<string>|null */
        private readonly ?array $roles,
        private readonly bool $skip_admin_check = false,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('invite', 'invite_url');

        // Rails: granting_admin_without_being_admin? guard.
        if ($this->grantingAdminWithoutBeingAdmin()) {
            return $result->forbiddenFailure('cannot_grant_admin');
        }

        $errors = $this->validate();
        if ($errors !== []) {
            return $result->validationFailure($errors);
        }

        $invite = new Invite([
            'organization_id' => $this->current_organization->id,
            'email' => $this->email,
            'token' => $this->generateToken(),
            'roles' => $this->roles,
        ]);

        // Rails: Invite.create! rescued into record_validation_failure! —
        // the port reproduces the model validations here (the frozen Invite
        // model carries no validation hooks): presence of email + token.
        $modelErrors = [];
        if (($invite->email ?? '') === '') {
            $modelErrors['email'] = ["can't be blank"];
        }

        if ($modelErrors !== []) {
            return $result->recordValidationFailure($modelErrors);
        }

        $invite->save();

        $result->invite = $invite;
        $result->invite_url = $this->buildInviteUrl((string) $invite->token);

        return $result;
    }

    /**
     * Port of Invites::ValidateService — every failing check adds its
     * `{field: [code]}` entry, mirroring `validation_failure!(errors:)`.
     *
     * @return array<string, list<string>>
     */
    private function validate(): array
    {
        $errors = [];

        // Rails: valid_invite? — a pending invite for the same email.
        if (Invite::query()->pending()
            ->where('organization_id', $this->current_organization->id)
            ->where('email', $this->email)
            ->exists()
        ) {
            $errors['invite'] = ['invite_already_exists'];
        }

        // Rails: valid_user? — an active membership whose user has the email.
        if (Membership::query()
            ->join('users', 'users.id', '=', 'memberships.user_id')
            ->where('memberships.organization_id', $this->current_organization->id)
            ->where('users.email', $this->email)
            ->where('memberships.status', 0)
            ->exists()
        ) {
            $errors['email'] = ['email_already_used'];
        }

        // Rails: valid_roles? — every role code must exist for the org
        // (predefined ones carry a null organization_id).
        $roles = $this->roles;
        if ($roles === null || $roles === []) {
            $errors['roles'] = ['invalid_role'];
        } else {
            $found = Role::query()
                ->whereIn('code', $roles)
                ->where(function ($query): void {
                    $query->whereNull('organization_id')
                        ->orWhere('organization_id', $this->current_organization->id);
                })
                ->whereNull('deleted_at')
                ->pluck('code')
                ->all();

            if (array_diff($roles, $found) !== []) {
                $errors['roles'] = ['invalid_role'];
            }
        }

        return $errors;
    }

    /**
     * Rails: `granting_admin_without_being_admin?` — granting the "admin"
     * role requires the acting membership to carry the admin role.
     */
    private function grantingAdminWithoutBeingAdmin(): bool
    {
        if ($this->skip_admin_check) {
            return false;
        }

        if (! in_array('admin', (array) ($this->roles ?? []), true)) {
            return false;
        }

        $membership = $this->actingMembership();

        // Rails: acting_membership&.admin? — roles.admins.exists? through the
        // kept membership_roles pivot.
        if ($membership === null) {
            return true;
        }

        return ! $membership->roles()
            ->where('roles.admin', true)
            ->exists();
    }

    /** Rails: organization.memberships.active.find_by(user:). */
    private function actingMembership(): ?Membership
    {
        if ($this->user === null) {
            return null;
        }

        /** @var Membership|null */
        return $this->current_organization->activeMemberships()
            ->where('user_id', $this->user->id)
            ->first();
    }

    /** Rails: generate_token — SecureRandom.hex(20), retried on collision. */
    private function generateToken(): string
    {
        do {
            $token = bin2hex(random_bytes(20));
        } while (Invite::query()->where('token', $token)->exists());

        return $token;
    }

    /** Rails: "#{Rails.application.config.lago_front_url}/invitation/#{token}". */
    private function buildInviteUrl(string $token): string
    {
        return (string) config('lago.front_url').'/invitation/'.$token;
    }
}
