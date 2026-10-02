<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\User;
use App\Enums\MembershipStatus;
use App\Support\Utils\AuthToken;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Exceptions\ExecutionError;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::LoginUser (app/graphql/mutations/login_user.rb)
 * and the UsersService#login branch (app/services/users_service.rb).
 *
 * Rails-side side effects intentionally deferred to their own ledger rows:
 * SegmentIdentifyJob and UserDevices::RegisterService (jobs/trackers are not
 * part of this slice).
 */
class LoginUser
{
    public const EMAIL_PASSWORD = 'email_password';

    /**
     * @return array{token: string, user: User}
     *
     * @throws ExecutionError
     */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        $email = (string) ($args['email'] ?? '');
        $password = (string) ($args['password'] ?? '');

        // NOTE: Null byte injection. Prevent 500 errors.
        if (str_contains($email, "\u{0000}") || str_contains($password, "\u{0000}")) {
            throw Errors::validationError(['base' => ['incorrect_login_or_password']]);
        }

        // Rails: User.find_by(email:)&.authenticate(password)
        $user = User::query()->where('email', $email)->first();

        if ($user === null || ! $user->authenticate($password) || ! $this->hasActiveMembership($user)) {
            throw Errors::validationError(['base' => ['incorrect_login_or_password']]);
        }

        if (! $this->emailPasswordAuthorized($user)) {
            throw Errors::validationError([
                self::EMAIL_PASSWORD => ['login_method_not_authorized'],
            ]);
        }

        $token = AuthToken::encode($user, extra: ['login_method' => self::EMAIL_PASSWORD]);

        if ($token === null) {
            throw Errors::executionError(
                error: 'Internal Error',
                status: 500,
                code: 'token_encoding_error',
            );
        }

        return [
            'token' => $token,
            'user' => $user,
        ];
    }

    /** Rails: result.user.memberships.active.any? (active == 0). */
    private function hasActiveMembership(User $user): bool
    {
        return $user->memberships()
            ->where('status', MembershipStatus::Active)
            ->exists();
    }

    /**
     * Rails: user.active_organizations.pluck(:authentication_methods)
     *   .flatten.uniq.include?(email_password).
     */
    private function emailPasswordAuthorized(User $user): bool
    {
        $organizationIds = $user->memberships()
            ->where('status', MembershipStatus::Active)
            ->pluck('organization_id');

        $methods = $user->organizations()
            ->whereIn('organizations.id', $organizationIds)
            ->pluck('authentication_methods')
            ->flatten()
            ->unique();

        return $methods->contains(self::EMAIL_PASSWORD);
    }
}
