<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator;

use App\Http\Client\LagoHttpError;
use App\Services\BaseResult;

/**
 * Port of Rails' Integrations::Aggregator::CustomObjectService
 * (app/services/integrations/aggregator/custom_object_service.rb) — fetches
 * an already-deployed HubSpot custom object (by name) through the Nango
 * proxy, so the deploy services can reuse an existing object instead of
 * recreating it.
 *
 * TODO(port): the Throttling subsystem (the throttle!(:hubspot) call is a
 * no-op, see the aggregator BaseService).
 */
class CustomObjectService extends BaseService
{
    public function __construct(
        Integration $integration,
        protected readonly string $name,
    ) {
        parent::__construct($integration);
    }

    public function actionPath(): string
    {
        return 'v1/'.$this->provider().'/custom-object';
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('custom_object');

        try {
            $response = $this->http_client()->getWithBody(['name' => $this->name], $this->headers());

            $result->custom_object = new CustomObject(
                id: $response['id'] ?? null,
                object_type_id: $response['objectTypeId'] ?? null,
            );

            return $result;
        } catch (LagoHttpError $e) {
            // Rails: result.service_failure!(code: e.error_code, message:
            // e.message) — the raw HTTP code and body, not the digged pair.
            return $result->serviceFailure(
                code: (string) $e->errorCode,
                message: (string) $e->errorBody,
            );
        }
    }

    /**
     * Rails: the custom-object headers — the provider config key rides along.
     *
     * @return array<string, string|null>
     */
    protected function headers(): array
    {
        return [
            'Connection-Id' => $this->integration->getFromSecrets('connection_id'),
            'Authorization' => 'Bearer '.$this->secret_key(),
            'Provider-Config-Key' => $this->providerKey(),
        ];
    }
}
