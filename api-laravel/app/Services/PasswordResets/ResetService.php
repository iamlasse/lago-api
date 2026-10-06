<?php

declare(strict_types=1);

namespace App\Services\PasswordResets;

use App\Models\User;
use App\Services\BaseResult;
use App\Models\PasswordReset;
use App\Services\BaseService;
use App\Enums\MembershipStatus;
use App\Support\Utils\AuthToken;
use Illuminate\Support\Facades\DB;
use App\Support\Organizations\AuthenticationMethods;

/**
 * Port of Rails' PasswordResets::ResetService
 * (app/services/password_resets/reset_service.rb) — the password is updated
 * and the user is logged in (a fresh JWT) in one transaction; the reset row
 * is destroyed afterwards.
 *
 * Rails-side side effects intentionally deferred to their own ledger rows:
 * SegmentIdentifyJob, UserDevices::RegisterService and the security logs
 * (jobs/trackers are not part of this slice).
 */
class ResetService extends BaseService
{
    public function __construct(
        private readonly ?string $token,
        private readonly ?string $new_password,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('user', 'token');

        if ($this->new_password === null || $this->new_password === '') {
            return $result->singleValidationFailure('missing_password', 'new_password');
        }

        if ($this->token === null || $this->token === '') {
            return $result->singleValidationFailure('missing_token', 'token');
        }

        $passwordReset = PasswordReset::query()
            ->where('expire_at', '>', now())
            ->where('token', $this->token)
            ->first();

        if ($passwordReset === null) {
            return $result->notFoundFailure('password_reset');
        }

        /** @var User $user */
        $user = $passwordReset->user;

        return $this->rescueFailures(function () use ($result, $passwordReset, $user): BaseResult {
            DB::transaction(function () use ($result, $passwordReset, $user): void {
                $user->password = $this->new_password;
                $user->save();

                // Rails: UsersService.call(:login, user.email,
                // new_password) — the login branch ported inline (the
                // LoginUser mutation carries the same logic; there is no
                // shared UsersService in the port). A login failure
                // propagates as the service result, exactly like Rails'
                // `result = ... .tap { password_reset.destroy! }`.
                $login = $this->login($result, $user);

                $result->user = $login['user'];
                $result->token = $login['token'];

                $passwordReset->delete();
            });

            return $result;
        }, $result);
    }

    /**
     * Rails: UsersService#login — require an active membership and the
     * email_password login method; a JWT is minted on success.
     *
     * @return array{user: User, token: ?string}
     */
    private function login(BaseResult $result, User $user): array
    {
        if (! $user->memberships()->where('status', MembershipStatus::Active)->exists()) {
            throw new \App\Services\Failures\ValidationFailure(
                $result,
                ['base' => ['incorrect_login_or_password']],
            );
        }

        $methods = $user->activeMemberships()
            ->with('organization')
            ->get()
            ->pluck('organization.authentication_methods')
            ->flatten()
            ->unique()
            ->all();

        if (! in_array(AuthenticationMethods::EMAIL_PASSWORD, (array) $methods, true)) {
            throw new \App\Services\Failures\ValidationFailure(
                $result,
                [AuthenticationMethods::EMAIL_PASSWORD => ['login_method_not_authorized']],
            );
        }

        return [
            'user' => $user,
            'token' => AuthToken::encode($user, extra: [
                'login_method' => AuthenticationMethods::EMAIL_PASSWORD,
            ]),
        ];
    }
}
