<?php

declare(strict_types=1);

namespace App\Services\Auth\Okta;

use App\Models\Invite;
use App\Services\BaseResult;
use App\Http\Client\LagoHttpClient;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Cache;
use App\Models\Integrations\OktaIntegration;
use App\Services\BaseService as RootBaseService;

/**
 * Port of Rails' Auth::Okta::BaseService (app/services/auth/okta/
 * base_service.rb) — the guard methods shared by the Okta login/authorize/
 * accept-invite services.
 */
abstract class BaseService extends RootBaseService
{
    /** The authorization code (the subclass constructor sets it, like Rails' attr_reader). */
    protected string $code = '';

    /** Rails: "#{Rails.application.config.lago_front_url}/auth/okta/callback". */
    public static function redirectUri(): string
    {
        return (string) config('lago.front_url').'/auth/okta/callback';
    }

    /** Rails: `90.seconds` — the authorize state TTL. */
    public static function stateTtl(): \Illuminate\Support\Carbon
    {
        return Date::now()->addSeconds(90);
    }

    /**
     * Rails: SecureRandom.hex (16 bytes → 32 chars) — the generated password
     * of users created through an SSO flow.
     */
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

        $email = Cache::get((string) $state);

        if (! is_string($email) || mb_trim($email) === '') {
            throw new ValidationError('state_not_found');
        }

        Cache::forget((string) $state);

        $result->email = $email;
    }

    /**
     * Rails: check_okta_integration — the email's domain must match the
     * settings domain of an Okta integration.
     */
    protected function checkOktaIntegration(string $email, BaseResult $result): void
    {
        $emailDomain = array_reverse(explode('@', $email))[0];

        $oktaIntegration = OktaIntegration::query()
            ->whereNotNull('settings->domain')
            ->where('settings->domain', $emailDomain)
            ->first();

        if ($oktaIntegration === null) {
            throw new ValidationError('domain_not_configured');
        }

        $result->okta_integration = $oktaIntegration;
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

    /** Rails: query_okta_access_token — the authorization-code exchange. */
    protected function queryOktaAccessToken(BaseResult $result): void
    {
        /** @var OktaIntegration $oktaIntegration */
        $oktaIntegration = $result->okta_integration;

        $tokenClient = new LagoHttpClient(
            "https://{$oktaIntegration->host()}/oauth2/v1/token",
            blockPrivateAddresses: true,
        );

        $response = $tokenClient->postUrlEncoded([
            'client_id' => $oktaIntegration->clientId(),
            'client_secret' => $oktaIntegration->clientSecret(),
            'grant_type' => 'authorization_code',
            'code' => $this->code,
            'redirect_uri' => self::redirectUri(),
        ]);

        $result->okta_access_token = $response['access_token'] ?? null;
    }

    /** Rails: check_userinfo — the userinfo email must match the state email. */
    protected function checkUserinfo(string $email, BaseResult $result): void
    {
        /** @var OktaIntegration $oktaIntegration */
        $oktaIntegration = $result->okta_integration;

        $userinfoClient = new LagoHttpClient(
            "https://{$oktaIntegration->host()}/oauth2/v1/userinfo",
            blockPrivateAddresses: true,
        );

        $response = $userinfoClient->get([
            'Authorization' => 'Bearer '.$result->okta_access_token,
        ]);

        if (($response['email'] ?? null) !== $email) {
            throw new ValidationError('okta_userinfo_error');
        }

        $result->userinfo = $response;
    }
}
