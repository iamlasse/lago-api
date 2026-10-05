<?php

declare(strict_types=1);

namespace App\Services\Auth\EntraId;

use Throwable;
use App\Models\User;
use App\Services\BaseResult;
use App\Enums\MembershipStatus;
use App\Support\Utils\AuthToken;
use App\Support\Organizations\AuthenticationMethods;

/**
 * Port of Rails' Auth::EntraId::LoginService (app/services/auth/entra_id/
 * login_service.rb) — completes the authorization-code flow, provisionally
 * creating the user and their membership on the integration's organization.
 *
 * Rails-side side effects intentionally deferred to their own ledger rows:
 * UserDevices::RegisterService (devices/jobs are not part of this slice).
 */
class LoginService extends BaseService
{
    public function __construct(
        string $code,
        private readonly string $state,
    ) {
        parent::__construct();

        $this->code = $code;
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult(
            'email',
            'entra_id_integration',
            'entra_id_access_token',
            'userinfo',
            'user',
            'token',
        );

        try {
            $this->checkState($this->state, $result);
            $this->checkCode($this->code);
            $this->checkEntraIdIntegration($result->email, $result);

            $this->queryEntraIdAccessToken($result);
            $this->checkUserinfo($result->email, $result);

            $this->findOrCreateUser($result);
            $this->findOrCreateMembership($result);

            if (! $this->organizationsAllow($result->user, AuthenticationMethods::ENTRA_ID)) {
                return $result->singleValidationFailure(
                    'login_method_not_authorized',
                    AuthenticationMethods::ENTRA_ID,
                );
            }

            // UserDevices::RegisterService.call!(user:) — deferred.

            return $this->generateToken($result);
        } catch (ValidationError $e) {
            return $result->singleValidationFailure($e->getMessage());
        } catch (\App\Http\Client\LagoHttpError $e) {
            return $result->singleValidationFailure('entra_id_request_error');
        }
    }

    /** Rails: generate_token (rescue → token_encoding_error, no raise). */
    private function generateToken(BaseResult $result): BaseResult
    {
        try {
            $result->token = AuthToken::encode($result->user, extra: [
                'login_method' => AuthenticationMethods::ENTRA_ID,
            ]);
        } catch (Throwable $e) {
            $result->serviceFailure('token_encoding_error', $e->getMessage(), $e);
        }

        return $result;
    }

    /** Rails: find_or_create_user — SecureRandom.hex(16) password. */
    private function findOrCreateUser(BaseResult $result): void
    {
        $user = User::query()->firstOrNew(['email' => $result->email]);

        if (! $user->exists) {
            $user->password = bin2hex(random_bytes(16));
            $user->save();
        }

        $result->user = $user;
    }

    /** Rails: find_or_create_membership. */
    private function findOrCreateMembership(BaseResult $result): void
    {
        /** @var \App\Models\Integrations\EntraIdIntegration $integration */
        $integration = $result->entra_id_integration;

        $result->user->memberships()->firstOrCreate([
            'organization_id' => $integration->organization_id,
        ]);
    }

    /**
     * Rails: `user.active_organizations.pluck(:authentication_methods)
     * .flatten.uniq.include?(method)`.
     */
    private function organizationsAllow(User $user, string $method): bool
    {
        $organizationIds = $user->memberships()
            ->where('status', MembershipStatus::Active->value)
            ->pluck('organization_id');

        $methods = $user->organizations()
            ->whereIn('organizations.id', $organizationIds)
            ->pluck('authentication_methods')
            ->flatten()
            ->unique();

        return $methods->contains($method);
    }
}
