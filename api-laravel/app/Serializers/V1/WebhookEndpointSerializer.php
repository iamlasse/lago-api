<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Models\WebhookEndpoint;
use App\Serializers\Base\ModelSerializer;
use App\Serializers\V1\Concerns\FormatsDatetime;

/**
 * Port of Rails' V1::WebhookEndpointSerializer
 * (app/serializers/v1/webhook_endpoint_serializer.rb).
 */
class WebhookEndpointSerializer extends ModelSerializer
{
    use FormatsDatetime;

    public function serialize(): array
    {
        /** @var WebhookEndpoint $endpoint */
        $endpoint = $this->model;

        return [
            'lago_id' => $endpoint->id,
            'lago_organization_id' => $endpoint->organization_id,
            'webhook_url' => $endpoint->webhook_url,
            'signature_algo' => $endpoint->signatureAlgoValue(),
            'name' => $endpoint->name,
            'event_types' => $endpoint->event_types,
            'created_at' => $this->serializeDatetime($endpoint->created_at),
        ];
    }
}
