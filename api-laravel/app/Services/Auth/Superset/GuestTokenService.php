<?php

declare(strict_types=1);

namespace App\Services\Auth\Superset;

use Throwable;
use JsonException;
use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Http\Client\LagoHttpError;
use Illuminate\Http\Client\ConnectionException;

/**
 * Port of Auth::Superset::GuestTokenService
 * (app/services/auth/superset/guest_token_service.rb): mints a single,
 * fresh Superset guest token for one dashboard, scoped to the organization
 * through a row-level-security clause. Used to renew the token while an
 * embedded dashboard stays open (guest tokens are short-lived), so the
 * front end can keep the session alive without a full page reload.
 */
class GuestTokenService extends BaseService
{
    use Client;

    /** The service's result — the trait's failure raisers write to it. */
    protected BaseResult $result;

    public function __construct(
        protected readonly Organization $organization,
        protected readonly string $dashboardId,
        protected readonly array $user = [],
    ) {
        parent::__construct();
    }

    /** Port of `#call` — the trailing rescue block maps the failure modes. */
    public function execute(): BaseResult
    {
        $this->result = static::makeResult('guest_token');

        try {
            return $this->run($this->result);
        } catch (ConnectionException $e) {
            return $this->result->serviceFailure('superset_timeout', 'Superset request timed out: '.$e->getMessage(), $e);
        } catch (JsonException $e) {
            return $this->result->serviceFailure('superset_invalid_response', 'Invalid JSON response from Superset: '.$e->getMessage(), $e);
        } catch (Throwable $e) {
            return $this->result->serviceFailure('superset_error', 'Superset operation failed: '.$e->getMessage(), $e);
        }
    }

    private function run(BaseResult $result): BaseResult
    {
        if (! $this->ensureSupersetConfigured()) {
            return $result;
        }

        $authResult = $this->authenticateWithApi();
        if (! $authResult['success']) {
            return $result;
        }

        $this->accessToken = $authResult['access_token'];

        $csrfResult = $this->getCsrfToken();
        if (! $csrfResult['success']) {
            return $result;
        }

        $this->csrfToken = $csrfResult['csrf_token'];

        return $this->mintGuestToken($result);
    }

    private function mintGuestToken(BaseResult $result): BaseResult
    {
        try {
            $body = [
                'resources' => [['id' => $this->dashboardId, 'type' => 'dashboard']],
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

            if ($guestToken === null || $guestToken === '') {
                return $result->serviceFailure('superset_guest_token_failed', 'No guest token received from Superset');
            }

            $result->guest_token = $guestToken;

            return $result;
        } catch (LagoHttpError $e) {
            return $result->serviceFailure('superset_guest_token_failed', 'Failed to mint guest token: '.json_encode($e->errorBody), $e);
        }
    }
}
