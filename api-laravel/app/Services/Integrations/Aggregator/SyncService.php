<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator;

use App\Services\BaseResult;

/**
 * Port of Rails' Integrations::Aggregator::SyncService
 * (app/services/integrations/aggregator/sync_service.rb) — the Nango
 * "sync/trigger" call listing the per-provider syncs.
 */
class SyncService extends BaseService
{
    /** The per-service result the processing writes into. */
    protected BaseResult $result;

    public function actionPath(): string
    {
        return 'sync/trigger';
    }

    public function execute(): BaseResult
    {
        $this->result = BaseResult::of('response');

        $payload = [
            'provider_config_key' => $this->providerKey(),
            'syncs' => $this->sync_list(),
        ];

        $response = $this->http_client()->postWithResponse($payload, $this->headers());

        $this->result()->response = $response;

        return $this->result();
    }

    protected function result(): BaseResult
    {
        return $this->result;
    }

    /**
     * Rails: `sync_list` — the named Nango syncs per integration type.
     *
     * @return list<string>
     */
    private function sync_list(): array
    {
        $list = match ($this->integration?->type) {
            'Integrations::NetsuiteIntegration' => [
                'subsidiaries' => 'netsuite-subsidiaries-sync',
            ],
            'Integrations::XeroIntegration' => [
                'accounts' => 'xero-accounts-sync',
                'items' => 'xero-items-sync',
                'contacts' => 'xero-contacts-sync',
            ],
            default => [],
        };

        // TODO(port): the `options[:only_items]` / `options[:only_accounts]`
        // narrowing (the ItemsService leg is unported).
        return array_values($list);
    }
}
