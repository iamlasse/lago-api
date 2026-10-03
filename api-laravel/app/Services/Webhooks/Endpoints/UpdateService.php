<?php

declare(strict_types=1);

namespace App\Services\Webhooks\Endpoints;

use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\WebhookEndpoint;
use App\Services\Failures\FailedResult;
use App\Enums\WebhookEndpointSignatureAlgo;

use function array_key_exists;

/**
 * Port of Rails' WebhookEndpoints::UpdateService
 * (app/services/webhook_endpoints/update_service.rb).
 *
 * The Rails service relies on the WebhookEndpoint model's validations —
 * ported in EndpointValidations.
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): Utils::SecurityLog.produce("webhook_endpoint.updated",
 *   with the webhook_url / signature_algo diff).
 */
class UpdateService extends BaseService
{
    public function __construct(
        private readonly string $id,
        private readonly Organization $organization,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('webhook_endpoint');
        $params = $this->params;

        $endpoint = $this->organization
            ->webhookEndpoints()
            ->where('id', $this->id)
            ->first();

        if ($endpoint === null) {
            return $result->notFoundFailure('webhook_endpoint');
        }

        if (array_key_exists('webhook_url', $params)) {
            $endpoint->webhook_url = $params['webhook_url'];
        }

        if (array_key_exists('signature_algo', $params)) {
            // Rails: params[:signature_algo]&.to_sym (an unknown enum name
            // raises in the Rails setter; we fall back to the jwt default).
            $endpoint->signature_algo = WebhookEndpointSignatureAlgo::fromOption($params['signature_algo']) ?? 0;
        }

        if (array_key_exists('name', $params)) {
            $endpoint->name = $params['name'];
        }

        $eventTypeErrors = [];

        if (array_key_exists('event_types', $params)) {
            $eventTypeErrors = EndpointValidations::assignEventTypes($endpoint, $params['event_types']);
        }

        try {
            $errors = [];

            $webhookUrl = $endpoint->webhook_url;

            if (($webhookUrl ?? '') === '') {
                // Rails: validates :webhook_url, presence: true.
                $errors['webhook_url'] = ['value_is_mandatory'];
            } elseif ($webhookUrl !== null) {
                $urlErrors = EndpointValidations::validateWebhookUrl($endpoint, (string) $webhookUrl);

                if ($urlErrors !== []) {
                    $errors['webhook_url'] = $urlErrors;
                }
            }

            if ($eventTypeErrors !== []) {
                $errors['event_types'] = $eventTypeErrors;
            }

            if ($errors !== []) {
                $result->recordValidationFailure($errors)->raiseIfError();
            }

            $endpoint->save();

            // TODO(port): Utils::SecurityLog.produce("webhook_endpoint.updated").

            $result->webhook_endpoint = $endpoint;

            return $result;
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }
}
