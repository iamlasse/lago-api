<?php

declare(strict_types=1);

namespace App\Services\Auth\EntraId;

use App\Models\Invite;
use App\Services\BaseResult;
use App\Http\Client\LagoHttpClient;
use App\Models\Integrations\EntraIdIntegration;
use App\Services\BaseService as RootBaseService;

/**
 * Port of Rails' Auth::EntraId::BaseService (app/services/auth/entra_id/
 * base_service.rb) — the guard methods shared by the Entra ID
 * login/authorize/accept-invite services.
 */
abstract class BaseService extends RootBaseService
{
    /**
     * Microsoft's OIDC userinfo endpoint. Azure AD exposes userinfo through
     * Microsoft Graph (not the tenant login host), so it is the same for every
     * commercial Entra tenant. Sovereign clouds (US Gov, China) use a different
     * Graph host and are out of scope for this integration.
     */
    public const MICROSOFT_GRAPH_USERINFO_URL = 'https://graph.microsoft.com/oidc/userinfo';

    /** The authorization code (the subclass constructor sets it, like Rails' attr_reader). */
    protected string $code = '';

    /** Rails: "#{Rails.application.config.lago_front_url}/auth/entra/callback". */
    public static function redirectUri(): string
    {
        return (string) config('lago.front_url').'/auth/entra/callback';
    }

    /** Rails: `90.seconds` — the authorize state TTL. */
    public static function stateTtl(): \Illuminate\Support\Carbon
    {
        return \Illuminate\Support\Facades\Date::now()->addSeconds(90);
    }

    /** Rails: SecureRandom.hex — the generated password of SSO-created users. */
    protected static function generatedPassword(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** Rails: check_code. */
    protected function checkCode(?string $code): void
    {
        if (mb_trim((string) $code) === '') {
            throw new ValidationError('code_not_found');
        }
    }

    /**
     * Rails: check_state — the state written by the authorize service holds
     * the email; it is single-use (read + delete).
     */
    protected function checkState(?string $state, BaseResult $result): void
    {
        if (mb_trim((string) $state) === '') {
            throw new ValidationError('state_not_found');
        }

        $email = \Illuminate\Support\Facades\Cache::get((string) $state);

        if (! is_string($email) || mb_trim($email) === '') {
            throw new ValidationError('state_not_found');
        }

        \Illuminate\Support\Facades\Cache::forget((string) $state);

        $result->email = $email;
    }

    /**
     * Rails: check_entra_id_integration — the email's domain must match the
     * settings domain of an Entra ID integration.
     */
    protected function checkEntraIdIntegration(string $email, BaseResult $result): void
    {
        $emailDomain = array_reverse(explode('@', $email))[0];

        $entraIdIntegration = EntraIdIntegration::query()
            ->whereNotNull('settings->domain')
            ->where('settings->domain', $emailDomain)
            ->first();

        if ($entraIdIntegration === null) {
            throw new ValidationError('domain_not_configured');
        }

        $result->entra_id_integration = $entraIdIntegration;
    }

    /** Rails: check_invite — a pending invite with matching email. */
    protected function checkInvite(?string $inviteToken, string $email, BaseResult $result): void
    {
        $invite = Invite::query()
            ->pending()
            ->where('token', $inviteToken)
            ->first();

        if ($invite === null) {
            throw new ValidationError('invite_not_found');
        }

        if ($invite->email !== $email) {
            throw new ValidationError('invite_email_mismatch');
        }

        $result->invite = $invite;
    }

    /** Rails: query_entra_id_access_token — the authorization-code exchange. */
    protected function queryEntraIdAccessToken(BaseResult $result): void
    {
        /** @var EntraIdIntegration $integration */
        $integration = $result->entra_id_integration;

        $tokenClient = new LagoHttpClient(
            "https://{$integration->host()}/{$integration->tenantId()}/oauth2/v2.0/token",
            blockPrivateAddresses: true,
        );

        $response = $tokenClient->postUrlEncoded([
            'client_id' => $integration->clientId(),
            'client_secret' => $integration->clientSecret(),
            'grant_type' => 'authorization_code',
            'code' => $this->code,
            'redirect_uri' => self::redirectUri(),
            'scope' => 'openid profile email',
        ]);

        $result->entra_id_access_token = $response['access_token'] ?? null;
    }

    /**
     * Rails: check_userinfo — Entra ID only returns the `email` claim when the
     * account has a mail attribute and the claim is configured, so we fall back
     * to `preferred_username` (the UPN). Comparison is case-insensitive as
     * Entra ID casing is not guaranteed to match the typed email.
     */
    protected function checkUserinfo(string $email, BaseResult $result): void
    {
        $userinfoClient = new LagoHttpClient(self::MICROSOFT_GRAPH_USERINFO_URL);

        $response = $userinfoClient->get([
            'Authorization' => 'Bearer '.$result->entra_id_access_token,
        ]);

        $responseEmail = $response['email'] ?? $response['preferred_username'] ?? null;

        if (! is_string($responseEmail) || mb_strtolower($responseEmail) !== mb_strtolower($email)) {
            throw new ValidationError('entra_id_userinfo_error');
        }

        $result->userinfo = $response;
    }
}
