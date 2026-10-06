<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator;

use App\Services\BaseResult;

/**
 * Port of Rails' Integrations::Aggregator::SubsidiariesService
 * (…/aggregator/subsidiaries_service.rb) — the provider subsidiaries lookup
 * behind the `integrationSubsidiaries` GraphQL query. Each record carries
 * the external id + name (Rails' Subsidiary data define).
 */
class SubsidiariesService extends BaseService
{
    public function __construct(
        \App\Models\Integration $integration,
    ) {
        parent::__construct($integration);
    }

    public function actionPath(): string
    {
        return 'v1/'.$this->provider().'/subsidiaries';
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('subsidiaries');

        $response = $this->http_client()->get(headers: $this->headers());

        $records = is_array($response) ? ($response['records'] ?? []) : [];

        $result->subsidiaries = array_map(
            fn (array $subsidiary): object => (object) [
                'external_id' => $subsidiary['id'] ?? null,
                'external_name' => $subsidiary['name'] ?? null,
            ],
            array_values(array_filter(
                is_array($records) ? $records : [],
                fn ($record): bool => is_array($record),
            )),
        );

        return $result;
    }

    /**
     * Rails: the subsidiaries headers — the connection secret and the Nango
     * provider config key.
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
