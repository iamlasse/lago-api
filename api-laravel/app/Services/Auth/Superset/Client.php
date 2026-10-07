<?php

declare(strict_types=1);

namespace App\Services\Auth\Superset;

use JsonException;
use App\Http\Client\LagoSessionClient;

/**
 * Port of Auth::Superset::Client (app/services/auth/superset/client.rb):
 * shared plumbing for talking to the Superset API — configuration checks,
 * admin authentication, CSRF handling and header builders. Used by the
 * services that need to reach Superset (dashboards listing and guest token
 * minting), so the multi-step auth flow lives in one place.
 */
trait Client
{
    protected ?string $accessToken = null;

    protected ?string $csrfToken = null;

    protected ?LagoSessionClient $httpClient = null;

    /**
     * Port of `http_client` — LagoHttpClient::SessionClient.new(base_url)
     * (30s read/open timeouts are the gem's defaults).
     */
    protected function httpClient(): LagoSessionClient
    {
        if ($this->httpClient === null) {
            $this->httpClient = new LagoSessionClient($this->supersetBaseUrl());
        }

        return $this->httpClient;
    }

    /** @return array<string, string> */
    protected function baseHeaders(string $refererPath = '/'): array
    {
        return [
            'Origin' => $this->supersetBaseUrl(),
            'Referer' => $this->supersetBaseUrl().$refererPath,
        ];
    }

    /** @return array<string, string> */
    protected function apiHeaders(string $refererPath = '/'): array
    {
        return array_merge($this->baseHeaders($refererPath), ['Accept' => 'application/json']);
    }

    /** @return array<string, string> */
    protected function authenticatedApiHeaders(string $refererPath = '/'): array
    {
        return array_merge($this->apiHeaders($refererPath), [
            'Authorization' => 'Bearer '.$this->accessToken,
            'X-CSRFToken' => (string) $this->csrfToken,
        ]);
    }

    /** @return array<string, string> */
    protected function authenticatedJsonHeaders(string $refererPath = '/'): array
    {
        return array_merge($this->authenticatedApiHeaders($refererPath), [
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * Port of `authenticate_with_api` — returns the access token, or null
     * when the login failed (the result is marked failed with the Rails
     * error code before returning).
     *
     * @return array{success: bool, access_token?: string}
     */
    protected function authenticateWithApi(): array
    {
        $body = [
            'username' => $this->supersetUsername(),
            'password' => $this->supersetPassword(),
            'provider' => 'db',
        ];

        $headers = array_merge($this->apiHeaders('/login/'), ['Content-Type' => 'application/json']);

        try {
            $response = $this->httpClient()->post('/api/v1/security/login', $body, $headers);

            $parsedResponse = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);

            $accessToken = $parsedResponse['access_token'] ?? null;
        } catch (\App\Http\Client\LagoHttpError $e) {
            $this->result->serviceFailure(
                'superset_auth_failed',
                'Failed to authenticate with Superset: '.$e->errorCode.' '.$e->getMessage(),
                $e,
            );

            return ['success' => false];
        } catch (JsonException $e) {
            // Rails: JSON::ParserError propagates to the service's rescue
            // block, which rewrites it as superset_invalid_response.
            throw $e;
        }

        if ($accessToken === null || $accessToken === '') {
            $this->result->serviceFailure('superset_auth_failed', 'No access token received from Superset');

            return ['success' => false];
        }

        return ['success' => true, 'access_token' => (string) $accessToken];
    }

    /**
     * Port of `get_csrf_token` — returns the CSRF token, or null when the
     * call failed (the result is marked failed first).
     *
     * @return array{success: bool, csrf_token?: string}
     */
    protected function getCsrfToken(): array
    {
        $headers = array_merge($this->apiHeaders(), ['Authorization' => 'Bearer '.$this->accessToken]);

        try {
            $response = $this->httpClient()->get('/api/v1/security/csrf_token/', $headers);

            $parsedResponse = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);

            $csrfToken = $parsedResponse['result'] ?? null;
        } catch (\App\Http\Client\LagoHttpError $e) {
            $this->result->serviceFailure('superset_csrf_failed', 'Failed to get CSRF token: '.$e->errorBody, $e);

            return ['success' => false];
        }

        if ($csrfToken === null || $csrfToken === '') {
            $this->result->serviceFailure('superset_no_csrf_token', 'No CSRF token received from Superset');

            return ['success' => false];
        }

        return ['success' => true, 'csrf_token' => (string) $csrfToken];
    }

    /**
     * Port of `guest_user_info` — the caller-provided user info, or the
     * organization-derived guest identity.
     *
     * @return array<string, string>
     */
    protected function guestUserInfo(): array
    {
        if (! empty($this->user)) {
            return $this->user;
        }

        return [
            'first_name' => $this->organization->name ?: 'Guest',
            'last_name' => 'User',
            'username' => 'guest_'.$this->organization->id,
        ];
    }

    /**
     * Port of `ensure_superset_configured` — marks the result failed with
     * superset_missing_configuration when any of the three settings is
     * blank.
     */
    protected function ensureSupersetConfigured(): bool
    {
        $missingVars = [];

        if ($this->supersetBaseUrl() === null || $this->supersetBaseUrl() === '') {
            $missingVars[] = 'SUPERSET_URL';
        }

        if ($this->supersetUsername() === null || $this->supersetUsername() === '') {
            $missingVars[] = 'SUPERSET_USERNAME';
        }

        if ($this->supersetPassword() === null || $this->supersetPassword() === '') {
            $missingVars[] = 'SUPERSET_PASSWORD';
        }

        if ($missingVars !== []) {
            $this->result->serviceFailure(
                'superset_missing_configuration',
                'Superset configuration is incomplete. Missing: '.implode(', ', $missingVars),
            );

            return false;
        }

        return true;
    }

    protected function supersetBaseUrl(): ?string
    {
        return config('lago.superset.url');
    }

    protected function supersetUsername(): ?string
    {
        return config('lago.superset.username');
    }

    protected function supersetPassword(): ?string
    {
        return config('lago.superset.password');
    }
}
