<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Role;
use App\Models\User;
use App\Models\Membership;
use App\Models\MembershipRole;
use App\Support\Utils\AuthToken;
use App\GraphQL\Execution\Errors;
use Illuminate\Support\Facades\DB;
use App\Services\Organizations\CreateService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::RegisterUser
 * (app/graphql/mutations/register_user.rb) and the UsersService#register
 * branch (app/services/users_service.rb).
 *
 * Rails-side side effects intentionally deferred to their own ledger rows:
 * SegmentIdentifyJob, the `user.signed_up` security log and
 * UserDevices::RegisterService (jobs/trackers are not part of this slice).
 */
class RegisterUser
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        // The frozen SDL wraps the arguments in the `input:` object
        // (`registerUser(input: RegisterUserInput!)`), unwrapped like Rails'
        // `resolve(input:, **)`.
        $input = is_array($args['input'] ?? null) ? $args['input'] : $args;

        if (config('lago.signup_disabled') === true || env('LAGO_DISABLE_SIGNUP', 'false') === 'true') {
            throw Errors::notAllowedError('signup_disabled');
        }

        // User normalizes :email with EmailSanitizer (app/support/
        // email_sanitizer.rb): strip invisible chars, map dash lookalikes
        // to "-", trim.
        $email = $this->sanitizeEmail((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $organizationName = (string) ($input['organizationName'] ?? '');

        if ($email === '') {
            throw Errors::validationError(['email' => ["can't be blank"]]);
        }

        if ($password === '') {
            throw Errors::validationError(['password' => ["can't be blank"]]);
        }

        if (mb_strlen($password) < 6) {
            throw Errors::validationError(['password' => ['is too short']]);
        }

        if (mb_strlen($password) > 72) {
            throw Errors::validationError(['password' => ['is too long']]);
        }

        if (User::query()->where('email', $email)->exists()) {
            throw Errors::validationError(['email' => ['user_already_exists']]);
        }

        [$user, $organization, $membership, $token] = DB::transaction(function () use ($email, $password, $organizationName): array {
            $user = new User(['email' => $email, 'password' => $password]);
            $user->save();

            $organization = CreateService::callBang(params: [
                'name' => $organizationName,
                'document_numbering' => 'per_organization',
            ])->organization;

            $membership = Membership::create([
                'user_id' => $user->id,
                'organization_id' => $organization->id,
                'status' => 0,
            ]);

            // Rails: Role.admins.first! — the single global admin role
            // (unique partial index on admin).
            $adminRole = Role::query()->where('admin', true)->firstOrFail();

            MembershipRole::create([
                'organization_id' => $organization->id,
                'membership_id' => $membership->id,
                'role_id' => $adminRole->id,
            ]);

            $token = AuthToken::encode($user, extra: ['login_method' => LoginUser::EMAIL_PASSWORD]);

            return [$user, $organization, $membership, $token];
        });

        if ($token === null) {
            throw Errors::executionError(
                error: 'Internal Error',
                status: 500,
                code: 'token_encoding_error',
            );
        }

        return [
            'membership' => $membership,
            'organization' => $organization,
            'token' => $token,
            'user' => $user,
        ];
    }

    /**
     * Port of EmailSanitizer.call (app/support/email_sanitizer.rb) over
     * Regex::INVISIBLE_CHARS / Regex::DASH_LOOKALIKES_CHARS.
     */
    private function sanitizeEmail(string $email): string
    {
        if ($email === '') {
            return $email;
        }

        $email = preg_replace('/[\x{200B}\x{200C}\x{200D}\x{00A0}\x{200E}\x{200F}\x{FEFF}]/u', '', $email) ?? $email;
        $email = preg_replace('/[\x{2012}-\x{2015}\x{2043}\x{2212}]/u', '-', $email) ?? $email;

        return mb_trim($email);
    }
}
