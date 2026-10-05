<?php

declare(strict_types=1);

namespace App\Services\Users;

use Throwable;
use App\Models\Role;
use App\Models\Invite;
use App\Models\Membership;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\MembershipRole;
use App\Enums\MembershipStatus;
use App\Support\Utils\AuthToken;
use Illuminate\Support\Facades\DB;
use App\Support\Organizations\AuthenticationMethods;

/**
 * Port of the `register_from_invite` branch of Rails' UsersService
 * (app/services/users_service.rb) — called by Invites::AcceptService. Rails'
 * multi-action service is one class per action here (same convention as the
 * other UsersService branches, which are ported with their own slices).
 */
class RegisterFromInviteService extends BaseService
{
    public function __construct(private readonly Invite $invite, private readonly string $password)
    {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('user', 'organization', 'membership', 'token');

        // ActiveRecord::Base.transaction — Rails also rescues
        // ActiveRecord::RecordInvalid into record_validation_failure!, which
        // has no equivalent here: the port's models do not carry the
        // Rails-side validations (none apply to these records).
        DB::transaction(function () use ($result): void {
            // Rails: User.find_or_initialize_by(email: invite.email).
            $user = \App\Models\User::query()->firstOrNew(['email' => $this->invite->email]);

            if (! $user->exists) {
                $user->password = $this->password;
                $user->save();
            } elseif ($user->memberships()->where('status', MembershipStatus::Active->value)->doesntExist()) {
                $user->password = $this->password;
                $user->save();
            }

            $result->user = $user;
            $result->organization = $this->invite->organization;

            $membership = Membership::create([
                'user_id' => $user->id,
                'organization_id' => $result->organization->id,
            ]);
            $result->membership = $membership;

            foreach ((array) ($this->invite->roles ?? []) as $roleCode) {
                // Rails: Role.with_code(code).with_organization(
                //   invite.organization_id).first! — the organization scope
                // matches nil OR the organization (global roles).
                $role = Role::query()
                    ->where('code', $roleCode)
                    ->where(function ($query) use ($result): void {
                        $query->whereNull('organization_id')
                            ->orWhere('organization_id', $result->organization->id);
                    })
                    ->firstOrFail();

                MembershipRole::create([
                    'organization_id' => $result->organization->id,
                    'membership_id' => $membership->id,
                    'role_id' => $role->id,
                ]);
            }

            // UsersService#generate_token — note it always stamps the
            // EMAIL_PASSWORD login method; Invites::AcceptService
            // overwrites the token with the actual login method's.
            $result->token = $this->generateToken($result->user, $result);
        });

        return $result;
    }

    /** Rails: UsersService#generate_token — a failure is recorded, not raised. */
    private function generateToken(\App\Models\User $user, BaseResult $result): ?string
    {
        try {
            return AuthToken::encode($user, extra: [
                'login_method' => AuthenticationMethods::EMAIL_PASSWORD,
            ]);
        } catch (Throwable $e) {
            $result->serviceFailure('token_encoding_error', $e->getMessage(), $e);

            return null;
        }
    }
}
