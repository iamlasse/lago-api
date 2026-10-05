<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator;

use App\Services\BaseResult;

/**
 * Port of Rails' Integrations::Aggregator::SendRestletEndpointService
 * (app/services/integrations/aggregator/send_restlet_endpoint_service.rb) —
 * registers the customer's Netsuite restlet endpoint on the Nango
 * connection metadata.
 */
class SendRestletEndpointService extends BaseService
{
    /** The per-service result the processing writes into. */
    protected BaseResult $result;

    public function actionPath(): string
    {
        return 'connection/'.$this->integration->getFromSecrets('connection_id').'/metadata';
    }

    public function execute(): BaseResult
    {
        $this->result = BaseResult::of('response');

        $scriptEndpointUrl = $this->integration->getFromSettings('script_endpoint_url');

        if ($this->integration->type !== 'Integrations::NetsuiteIntegration') {
            return $this->result();
        }

        if ($scriptEndpointUrl === null || $scriptEndpointUrl === '') {
            return $this->result();
        }

        $payload = [
            'restletEndpoint' => $scriptEndpointUrl,
        ];

        $response = $this->http_client()->postWithResponse($payload, [
            'Provider-Config-Key' => 'netsuite-tba',
            'Authorization' => 'Bearer '.$this->secret_key(),
        ]);

        $this->result()->response = $response;

        return $this->result();
    }

    protected function result(): BaseResult
    {
        return $this->result;
    }
}
