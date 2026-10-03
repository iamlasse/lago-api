<?php

declare(strict_types=1);

namespace App\Services\Webhooks\Endpoints;

use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\WebhookEndpoint;
use App\Services\Failures\FailedResult;
use App\Enums\WebhookEndpointSignatureAlgo;

/**
 * Port of Rails' WebhookEndpoints::CreateService
 * (app/services/webhook_endpoints/create_service.rb).
 *
 * The Rails service relies on the WebhookEndpoint model's validations —
 * ported in EndpointValidations.
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): SegmentTrackJob "webhook_endpoint_created"
 *   (membership_id: CurrentContext.membership).
 * - TODO(port): Utils::SecurityLog.produce("webhook_endpoint.created").
 */
class CreateService extends BaseService
{
    public function __construct(
        private readonly Organization $organization,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('webhook_endpoint');
        $params = $this->params;

        $endpoint = $this->organization->webhookEndpoints()->make([
            'webhook_url' => $params['webhook_url'] ?? null,
            'name' => $params['name'] ?? null,
        ]);

        // Rails: params[:signature_algo]&.to_sym || :jwt (an unknown enum
        // name raises in the Rails setter; we fall back to the jwt default).
        $endpoint->signature_algo = WebhookEndpointSignatureAlgo::fromOption($params['signature_algo'] ?? null) ?? 0;

        $eventTypeErrors = [];

        if (array_key_exists('event_types', $params)) {
            $eventTypeErrors = EndpointValidations::assignEventTypes($endpoint, $params['event_types']);
        }

        try {
            $webhookUrl = (string) ($endpoint->webhook_url ?? '');

            $errors = [];

            if ($webhookUrl === '') {
                // Rails: validates :webhook_url, presence: true.
                $errors['webhook_url'] = ['value_is_mandatory'];
            } else {
                $urlErrors = EndpointValidations::validateWebhookUrl($endpoint, $webhookUrl);

                // Rails: validate :max_webhook_endpoints, on: :create. (The
                // :exceeded_limit symbol has no i18n entry — the message is
                // Rails' humanized fallback.)
                if ($this->organization->webhookEndpoints()->count() >= WebhookEndpoint::LIMIT) {
                    $urlErrors[] = 'Exceeded limit';
                }

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

            // TODO(port): SegmentTrackJob "webhook_endpoint_created" +
            // Utils::SecurityLog.produce("webhook_endpoint.created",
            //   resources: {webhook_url:, signature_algo:}).

            $result->webhook_endpoint = $endpoint;

            return $result;
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }
}
