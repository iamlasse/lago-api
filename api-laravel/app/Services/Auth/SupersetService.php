<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Http\Client\LagoHttpError;
use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\Auth\Superset\Client;
use Illuminate\Http\Client\ConnectionException;

/**
 * Port of Auth::SupersetService (app/services/auth/superset_service.rb):
 * lists every Superset dashboard of the instance and prepares it for
 * embedding — one embedded config + one guest token (RLS-scoped to the
 * organization) per dashboard.
 */
class SupersetService extends BaseService
{
    use Client;

    public function __construct(
        protected readonly Organization $organization,
        protected readonly array $user = [],
    ) {
        parent::__construct();
    }

    /**
     * Port of `#call` — the trailing rescue block maps the common failure
     * modes to their dedicated codes, everything else to superset_error.
     */
    public function execute(): BaseResult
    {
        $this->result = static::makeResult('dashboards');

        try {
            return $this->run($this->result);
        } catch (ConnectionException $e) {
            return $this->result->serviceFailure('superset_timeout', 'Superset request timed out: '.$e->getMessage(), $e);
        } catch (\JsonException $e) {
            return $this->result->serviceFailure('superset_invalid_response', 'Invalid JSON response from Superset: '.$e->getMessage(), $e);
        } catch (\Throwable $e) {
            return $this->result->serviceFailure('superset_error', 'Superset operation failed: '.$e->getMessage(), $e);
        }
    }

    /** The service's result — the trait's failure raisers write to it. */
    protected BaseResult $result;

    private function run(BaseResult $result): BaseResult
    {
        if (! $this->ensureSupersetConfigured()) {
            return $result;
        }

        // Step 1: Authenticate and get access token
        $authResult = $this->authenticateWithApi();
        if (! $authResult['success']) {
            return $result;
        }

        $this->accessToken = $authResult['access_token'];

        // Step 2: Get CSRF token (authenticated with Bearer token)
        $csrfResult = $this->getCsrfToken();
        if (! $csrfResult['success']) {
            return $result;
        }

        $this->csrfToken = $csrfResult['csrf_token'];

        // Step 3: Fetch all dashboards
        $dashboardsResult = $this->fetchDashboards($result);
        if (! $dashboardsResult['success']) {
            return $result;
        }

        // Step 4: Process each dashboard to ensure embedded config and get guest token
        $processedDashboards = [];

        foreach ($dashboardsResult['dashboards'] as $dashboard) {
            $embeddedConfig = $this->ensureEmbeddedConfig((string) $dashboard['id']);
            if (! $embeddedConfig['success']) {
                continue;
            }

            $guestTokenResult = $this->getGuestToken((string) $dashboard['id']);
            if (! $guestTokenResult['success']) {
                continue;
            }

            $processedDashboards[] = [
                'id' => (string) $dashboard['id'],
                'dashboard_title' => $dashboard['dashboard_title'] ?? null,
                'embedded_id' => $embeddedConfig['uuid'],
                'guest_token' => $guestTokenResult['guest_token'],
            ];
        }

        $result->dashboards = $processedDashboards;

        return $result;
    }

    /** @return array{success: bool, dashboards?: list<array<string, mixed>>} */
    private function fetchDashboards(BaseResult $result): array
    {
        try {
            $response = $this->httpClient()->get('/api/v1/dashboard/', $this->authenticatedApiHeaders());

            $parsedResponse = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);

            $dashboards = $parsedResponse['result'] ?? [];
        } catch (LagoHttpError $e) {
            $result->serviceFailure('superset_fetch_dashboards_failed', 'Failed to fetch dashboards: '.json_encode($e->errorBody), $e);

            return ['success' => false];
        }

        return ['success' => true, 'dashboards' => is_array($dashboards) ? $dashboards : []];
    }

    /** @return array{success: bool, uuid?: ?string, exists?: bool} */
    private function getEmbeddedConfig(string $dashboardId): array
    {
        try {
            $response = $this->httpClient()->get('/api/v1/dashboard/'.$dashboardId.'/embedded', $this->authenticatedApiHeaders());

            $parsedResponse = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);

            $uuid = $parsedResponse['result']['uuid'] ?? null;

            if ($uuid !== null) {
                return ['success' => true, 'uuid' => $uuid, 'exists' => true];
            }

            return ['success' => true, 'exists' => false];
        } catch (LagoHttpError|\JsonException) {
            return ['success' => true, 'exists' => false];
        }
    }

    /** @return array{success: bool, uuid?: string} */
    private function createEmbeddedConfig(string $dashboardId): array
    {
        try {
            $body = ['allowed_domains' => []];

            $response = $this->httpClient()->post(
                '/api/v1/dashboard/'.$dashboardId.'/embedded',
                $body,
                $this->authenticatedJsonHeaders(),
            );

            $parsedResponse = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);

            $uuid = $parsedResponse['result']['uuid'] ?? null;

            if ($uuid === null) {
                return ['success' => false];
            }

            return ['success' => true, 'uuid' => $uuid];
        } catch (LagoHttpError|\JsonException) {
            return ['success' => false];
        }
    }

    /** @return array{success: bool, uuid?: string} */
    private function ensureEmbeddedConfig(string $dashboardId): array
    {
        $embeddedConfig = $this->getEmbeddedConfig($dashboardId);

        if (! $embeddedConfig['success']) {
            return ['success' => false];
        }

        if ($embeddedConfig['exists']) {
            return ['success' => true, 'uuid' => $embeddedConfig['uuid']];
        }

        return $this->createEmbeddedConfig($dashboardId);
    }

    /** @return array{success: bool, guest_token?: mixed} */
    private function getGuestToken(string $dashboardId): array
    {
        try {
            $body = [
                'resources' => [['id' => $dashboardId, 'type' => 'dashboard']],
                'rls' => [
                    ['clause' => "organization_id = '".$this->organization->id."'"],
                ],
                'user' => $this->guestUserInfo(),
            ];

            $response = $this->httpClient()->post('/api/v1/security/guest_token/', $body, $this->authenticatedJsonHeaders());

            $parsedResponse = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);

            $guestToken = $parsedResponse['token']
                ?? $parsedResponse['result']
                ?? $parsedResponse['access_token']
                ?? null;

            if ($guestToken === null) {
                return ['success' => false];
            }

            return ['success' => true, 'guest_token' => $guestToken];
        } catch (LagoHttpError|\JsonException) {
            return ['success' => false];
        }
    }
}
