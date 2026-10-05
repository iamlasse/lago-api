<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator;

use App\Services\BaseResult;

/**
 * Port of Rails' Integrations::Aggregator::AccountInformationService
 * (…/aggregator/account_information_service.rb) — the Nango account
 * lookup the HubSpot portal-id job drives. The result carries a single
 * `id` (Rails' AccountInformation data define).
 */
class AccountInformationService extends BaseService
{
    public function __construct(
        \App\Models\Integration $integration,
    ) {
        parent::__construct($integration);
    }

    public function actionPath(): string
    {
        return 'v1/account-information';
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('account_information');

        $accountInformation = $this->http_client()->get(headers: $this->headers());

        $result->account_information = new AccountInformation(
            id: is_array($accountInformation) ? ($accountInformation['id'] ?? null) : null,
        );

        return $result;
    }

    /**
     * Rails: the account-information headers — the connection secret and
     * the Nango provider config key.
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
