<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Throwable;
use App\Models\Role;
use App\Models\User;
use RuntimeException;
use App\Models\Membership;
use App\Services\BaseResult;
use App\Services\BaseService;
use InvalidArgumentException;
use App\Models\MembershipRole;
use App\Enums\MembershipStatus;
use App\Support\Google\IdTokens;
use App\Support\Utils\AuthToken;
use Firebase\JWT\ExpiredException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Services\Invites\AcceptService;
use Firebase\JWT\SignatureInvalidException;
use App\Services\Organizations\CreateService;
use App\Support\Organizations\AuthenticationMethods;

/**
 * Port of Rails' Auth::GoogleService (app/services/auth/google_service.rb).
 *
 * Fidelity of the OAuth plumbing (no google-auth/signet gems here):
 *   - the authorize URL is the gem's WebUserAuthorizer endpoint,
 *     https://accounts.google.com/o/oauth2/auth, with the same base scope
 *     [profile, email, openid] and the front-end callback redirect;
 *   - the code exchange is the gem's Signet token call — POST
 *     https://oauth2.googleapis.com/token (form-encoded) — returning the
 *     id_token;
 *   - the id_token is verified like Google::Auth::IDTokens.verify_oidc:
 *     RS256 signature against Google's JWKS, aud = client id, iss =
 *     accounts.google.com (see App\Support\Google\IdTokens).
 *     SignatureError → "invalid_google_token"; the Signet AuthorizationError
 *     of a failed exchange → "invalid_google_code".
 *
 * Rails-side side effects intentionally deferred to their own ledger rows:
 * SegmentIdentifyJob, SegmentTrackJob, the security logs and
 * UserDevices::RegisterService (jobs/trackers are not part of this slice).
 */
class GoogleService extends BaseService
{
    public const BASE_SCOPE = ['profile', 'email', 'openid'];

    /** Rails: WebUserAuthorizer default authorization endpoint. */
    public const AUTH_URI = 'https://accounts.google.com/o/oauth2/auth';

    /** Rails: Signet/google-auth token endpoint. */
    public const TOKEN_URI = 'https://oauth2.googleapis.com/token';

    private function __construct(
        private readonly string $action,
        private readonly string $code = '',
        private readonly string $organizationName = '',
        private readonly string $inviteToken = '',
    ) {
        parent::__construct();
    }

    /** Rails: "#{Rails.application.config.lago_front_url}/auth/google/callback". */
    public static function redirectUri(): string
    {
        return (string) config('lago.front_url').'/auth/google/callback';
    }

    /** Rails: `described_class.call(:authorize_url, request)`. */
    public static function authorizeUrl(): BaseResult
    {
        return (new static('authorize_url'))->execute();
    }

    /** Rails: `described_class.call(:login, code)`. */
    public static function login(string $code): BaseResult
    {
        return (new static('login', code: $code))->execute();
    }

    /** Rails: `described_class.call(:register_user, code, organization_name)`. */
    public static function registerUser(string $code, string $organizationName): BaseResult
    {
        return (new static('register_user', code: $code, organizationName: $organizationName))->execute();
    }

    /** Rails: `described_class.call(:accept_invite, code, invite_token)`. */
    public static function acceptInvite(string $code, string $inviteToken): BaseResult
    {
        return (new static('accept_invite', code: $code, inviteToken: $inviteToken))->execute();
    }

    public function execute(): BaseResult
    {
        return match ($this->action) {
            'authorize_url' => $this->authorizeUrlResult(static::makeResult('url')),
            'login' => $this->loginResult(static::makeResult('user', 'token')),
            'register_user' => $this->registerUserResult(static::makeResult('user', 'organization', 'membership', 'token')),
            'accept_invite' => $this->acceptInviteResult(static::makeResult('membership', 'token', 'user')),
            default => throw new InvalidArgumentException("Unknown action {$this->action}"),
        };
    }

    // -- Actions ---------------------------------------------------------------

    private function authorizeUrlResult(BaseResult $result): BaseResult
    {
        if (($failure = $this->ensureGoogleAuthSetup()) !== null) {
            return $result->failWithError($failure);
        }

        $result->url = self::AUTH_URI.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId(),
            'redirect_uri' => self::redirectUri(),
            'scope' => implode(' ', self::BASE_SCOPE),
        ]);

        return $result;
    }

    private function loginResult(BaseResult $result): BaseResult
    {
        try {
            if (($failure = $this->ensureGoogleAuthSetup()) !== null) {
                return $result->failWithError($failure);
            }

            $googleOidc = $this->oidcVerifier();

            // Rails: User.find_by(email:)&.then { _1.memberships.active.any? }.
            $user = User::query()->where('email', $googleOidc['email'] ?? null)->first();

            if ($user === null
                || ! $user->memberships()
                    ->where('status', MembershipStatus::Active->value)
                    ->exists()) {
                return $result->singleValidationFailure('user_does_not_exist');
            }

            if (! $this->organizationsAllow($user, AuthenticationMethods::GOOGLE_OAUTH)) {
                return $result->singleValidationFailure(
                    'login_method_not_authorized',
                    AuthenticationMethods::GOOGLE_OAUTH,
                );
            }

            $result->user = $user;

            // UserDevices::RegisterService.call!(user:) — deferred.
            return $this->generateToken($result);
        } catch (SignatureInvalidException $e) {
            return $result->singleValidationFailure('invalid_google_token');
        } catch (ExpiredException $e) {
            // The gem raises IDTokens::ExpiredToken (not rescued) — rethrow.
            throw $e;
        } catch (\App\Services\Failures\FailedResult $e) {
            // A nested raise_if_error! — not rescued in Rails.
            throw $e;
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            // Transport failures of the exchange are not rescued — only
            // Signet::AuthorizationError (an error response) is.
            throw $e;
        } catch (Throwable $e) {
            // Rails: rescue Signet::AuthorizationError → invalid_google_code
            // (the token endpoint answered with an error).
            return $result->singleValidationFailure('invalid_google_code');
        }
    }

    private function registerUserResult(BaseResult $result): BaseResult
    {
        try {
            if (($failure = $this->ensureGoogleAuthSetup()) !== null) {
                return $result->failWithError($failure);
            }

            $googleOidc = $this->oidcVerifier();

            return $this->register($result, (string) ($googleOidc['email'] ?? ''), $this->organizationName);
        } catch (SignatureInvalidException $e) {
            return $result->singleValidationFailure('invalid_google_token');
        } catch (\App\Services\Failures\FailedResult $e) {
            // CreateService.call! failure — not rescued in Rails.
            throw $e;
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw $e;
        } catch (Throwable $e) {
            return $result->singleValidationFailure('invalid_google_code');
        }
    }

    private function acceptInviteResult(BaseResult $result): BaseResult
    {
        try {
            if (($failure = $this->ensureGoogleAuthSetup()) !== null) {
                return $result->failWithError($failure);
            }

            $googleOidc = $this->oidcVerifier();

            $invite = \App\Models\Invite::query()
                ->where('token', $this->inviteToken)
                ->where('status', \App\Enums\InviteStatus::Pending->value)
                ->first();

            if ($invite === null) {
                return $result->notFoundFailure('invite');
            }

            if (($googleOidc['email'] ?? null) !== $invite->email) {
                // NOTE: the typo ("mistmatch") is Rails' — kept verbatim.
                return $result->singleValidationFailure('invite_email_mistmatch');
            }

            return AcceptService::call(
                invite: $invite,
                password: bin2hex(random_bytes(16)),
                loginMethod: AuthenticationMethods::GOOGLE_OAUTH,
            );
        } catch (SignatureInvalidException $e) {
            return $result->singleValidationFailure('invalid_google_token');
        } catch (\App\Services\Failures\FailedResult $e) {
            // Invites::AcceptService failure — embed like Rails' result.
            return $result->failWithError($e);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw $e;
        } catch (Throwable $e) {
            return $result->singleValidationFailure('invalid_google_code');
        }
    }

    // -- Shared steps ------------------------------------------------------------

    /**
     * Rails: register(email, organization_name) — the UsersService#register
     * branch inlined (LAGO_DISABLE_SIGNUP gate, uniqueness, transaction).
     */
    private function register(BaseResult $result, string $email, string $organizationName): BaseResult
    {
        if (config('lago.signup_disabled') === true || env('LAGO_DISABLE_SIGNUP', 'false') === 'true') {
            return $result->notAllowedFailure('signup_disabled');
        }

        if (User::query()->where('email', $email)->exists()) {
            return $result->singleValidationFailure('user_already_exists', 'email');
        }

        DB::transaction(function () use ($result, $email, $organizationName): void {
            $user = new User(['email' => $email, 'password' => bin2hex(random_bytes(16))]);
            $user->save();
            $result->user = $user;

            $result->organization = CreateService::callBang(params: [
                'name' => $organizationName,
                'document_numbering' => 'per_organization',
            ])->organization;

            $result->membership = Membership::create([
                'user_id' => $user->id,
                'organization_id' => $result->organization->id,
            ]);

            // Rails: Role.find_by!(admin: true) — the single global admin role.
            $role = Role::query()->where('admin', true)->firstOrFail();

            MembershipRole::create([
                'organization_id' => $result->organization->id,
                'membership_id' => $result->membership->id,
                'role_id' => $role->id,
            ]);

            $this->generateToken($result);
        });

        // SegmentIdentifyJob, track_organization_registered and
        // UserDevices::RegisterService(skip_log: true) — deferred.

        return $result;
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

    private function generateToken(BaseResult $result): BaseResult
    {
        try {
            $result->token = AuthToken::encode($result->user, extra: [
                'login_method' => AuthenticationMethods::GOOGLE_OAUTH,
            ]);
        } catch (Throwable $e) {
            $result->serviceFailure('token_encoding_error', $e->getMessage(), $e);
        }

        return $result;
    }

    /**
     * Rails: oidc_verifier — exchange the code (Signet) then verify the
     * id_token (Google::Auth::IDTokens.verify_oidc, aud = client id).
     *
     * @return array<string, mixed>
     */
    private function oidcVerifier(): array
    {
        $response = Http::asForm()->post(self::TOKEN_URI, [
            'code' => $this->code,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'redirect_uri' => self::redirectUri(),
            'grant_type' => 'authorization_code',
        ]);

        if (! $response->successful()) {
            // Signet::AuthorizationError.
            throw new RuntimeException('Google token exchange failed');
        }

        $idToken = (string) ($response->json('id_token') ?? '');

        return IdTokens::verifyOidc($idToken, (string) $this->clientId());
    }

    private function clientId(): ?string
    {
        return config('lago.google_auth_client_id');
    }

    private function clientSecret(): ?string
    {
        return config('lago.google_auth_client_secret');
    }

    /**
     * Rails: ensure_google_auth_setup — a missing env pair is a
     * google_auth_missing_setup service failure.
     */
    private function ensureGoogleAuthSetup(): ?\App\Services\Failures\FailedResult
    {
        if (($this->clientId() ?? '') !== '' && ($this->clientSecret() ?? '') !== '') {
            return null;
        }

        return static::makeResult()
            ->serviceFailure('google_auth_missing_setup', 'Google auth is not set up')
            ->getError();
    }
}
