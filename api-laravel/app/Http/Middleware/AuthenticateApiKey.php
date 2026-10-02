<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use App\Models\ApiKey;
use App\Models\Organization;
use Illuminate\Http\Request;
use App\Support\CurrentContext;
use Illuminate\Support\Facades\Cache;
use App\Services\ApiKeys\CacheService;
use App\Exceptions\Api\ForbiddenException;
use App\Http\Controllers\Api\ApiController;
use App\Exceptions\Api\UnauthorizedException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Port of Api::BaseController's before_action chain:
 * authenticate -> set_context_source -> track_api_key_usage -> authorize.
 */
class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->authToken($request);

        if ($token === null) {
            throw new UnauthorizedException;
        }

        $controller = $request->route()?->getController();

        [$apiKey, $organization] = CacheService::call(
            $token,
            $controller instanceof ApiController ? $controller->cachedApiKey() : false,
        );

        if ($apiKey === null || $organization === null) {
            throw new UnauthorizedException;
        }

        $this->setContextSource($apiKey, $organization);

        if ($controller instanceof ApiController) {
            $controller->setCurrentApiKey($apiKey);
            $controller->setCurrentOrganization($organization);

            if ($controller->trackApiKeyUsage()) {
                $this->trackApiKeyUsage($apiKey);
            }

            $this->authorize($request, $apiKey, $organization, $controller);
        }

        return $next($request);
    }

    /**
     * Port of Api::BaseController#auth_token: `Authorization` split on
     * whitespace, second element — the scheme is NOT validated.
     */
    private function authToken(Request $request): ?string
    {
        $parts = preg_split('/\s+/', mb_trim((string) $request->headers->get('Authorization', ''))) ?: [];

        return $parts[1] ?? null;
    }

    private function setContextSource(ApiKey $apiKey, Organization $organization): void
    {
        CurrentContext::$source = 'api';
        CurrentContext::$apiKeyId = $apiKey->id;
        CurrentContext::$organization = $organization;
    }

    /**
     * Port of Api::BaseController#track_api_key_usage: last-used timestamp
     * is written to the cache (Rails writes without an expiry).
     */
    private function trackApiKeyUsage(ApiKey $apiKey): void
    {
        Cache::forever('api_key_last_used_'.$apiKey->id, now()->toIso8601String());
    }

    /**
     * Port of Api::BaseController#authorize.
     */
    private function authorize(Request $request, ApiKey $apiKey, Organization $organization, ApiController $controller): void
    {
        if ($this->permit($request, $apiKey, $organization, $controller)) {
            return;
        }

        throw new ForbiddenException(sprintf(
            '%s_action_not_allowed_for_%s',
            $this->mode($request),
            (string) $controller->resourceName(),
        ));
    }

    /**
     * Port of ApiKey#permit?: permissions are only enforced when the
     * organization has the (premium) api_permissions integration enabled;
     * otherwise every key is allowed.
     */
    private function permit(Request $request, ApiKey $apiKey, Organization $organization, ApiController $controller): bool
    {
        if (! $this->apiPermissionsEnabled($organization)) {
            return true;
        }

        $permissions = (array) ($apiKey->permissions ?? []);
        $modes = (array) ($permissions[$controller->resourceName()] ?? []);

        return in_array($this->mode($request), $modes, true);
    }

    /**
     * Port of Organization#api_permissions_enabled? (premium integrations
     * are defined via `define_method("#{name}_enabled?") { License.premium? &&
     * premium_integrations.include?(name) }`). License verification against
     * LAGO_LICENSE_URL is not ported yet — a configured LAGO_LICENSE token
     * is treated as premium.
     */
    private function apiPermissionsEnabled(Organization $organization): bool
    {
        return config('lago.license') !== null
            && in_array('api_permissions', (array) ($organization->premium_integrations ?? []), true);
    }

    /**
     * Port of Api::BaseController#mode: GET -> "read", anything else "write".
     */
    private function mode(Request $request): string
    {
        return $request->isMethod('GET') ? 'read' : 'write';
    }
}
