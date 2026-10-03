<?php

declare(strict_types=1);

namespace App\Services\Webhooks\Endpoints;

use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\WebhookEndpoint;

/**
 * Port of Rails' WebhookEndpoints::DestroyService
 * (app/services/webhook_endpoints/destroy_service.rb).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): SegmentTrackJob "webhook_endpoint_deleted"
 *   (membership_id: CurrentContext.membership).
 * - TODO(port): Utils::SecurityLog.produce("webhook_endpoint.deleted").
 */
class DestroyService extends BaseService
{
    public function __construct(
        private readonly ?WebhookEndpoint $webhookEndpoint,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('webhook_endpoint');

        if ($this->webhookEndpoint === null) {
            return $result->notFoundFailure('webhook_endpoint');
        }

        $this->webhookEndpoint->delete();

        // TODO(port): SegmentTrackJob "webhook_endpoint_deleted" +
        // Utils::SecurityLog.produce("webhook_endpoint.deleted").

        $result->webhook_endpoint = $this->webhookEndpoint;

        return $result;
    }
}
