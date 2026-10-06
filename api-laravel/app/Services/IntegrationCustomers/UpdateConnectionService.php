<?php

declare(strict_types=1);

namespace App\Services\IntegrationCustomers;

use App\Models\Integration;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\IntegrationCustomer;
use App\Jobs\IntegrationCustomers\UpdateJob;

/**
 * Port of Rails' IntegrationCustomers::UpdateConnectionService
 * (app/services/integration_customers/update_connection_service.rb) — the
 * routing attribute is updated locally; the provider contact is re-synced
 * through the UpdateJob (Anrok and Salesforce rows never reach the provider).
 */
class UpdateConnectionService extends BaseService
{

    /** Rails: Integrations::BaseIntegration PROVIDER_TYPES — integration STI type to provider key. */
    private const INTEGRATION_PROVIDER_KEYS = [
        Integration::ANROK_TYPE => 'anrok',
        Integration::AVALARA_TYPE => 'avalara',
        Integration::HUBSPOT_TYPE => 'hubspot',
        Integration::SALESFORCE_TYPE => 'salesforce',
        Integration::NETSUITE_TYPE => 'netsuite',
        Integration::XERO_TYPE => 'xero',
    ];

    public const NON_SYNCING_TYPES = [
        IntegrationCustomer::ANROK_TYPE,
        IntegrationCustomer::SALESFORCE_TYPE,
    ];

    public function __construct(
        public readonly ?IntegrationCustomer $integration_customer,
        /** @var array<string, mixed> */
        public readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('integration_customer');

        if ($this->integration_customer === null) {
            return $result->notFoundFailure('integration_customer');
        }

        $integrationCustomer = $this->integration_customer;

        if (array_key_exists('code', $this->params)) {
            $integrationCustomer->code = $this->params['code'];
            $integrationCustomer->save();
        }

        if ($this->syncProviderCustomer($integrationCustomer)) {
            /** @var Integration $integration */
            $integration = $integrationCustomer->integration;

            UpdateJob::dispatch(
                integration_customer_params: array_merge($this->params, [
                    'integration_type' => array_flip(IntegrationCustomer::PROVIDER_TYPES)[$integration->type] ?? null,
                    'integration_code' => $integration->code,
                ]),
                integration: $integration,
                integration_customer: $integrationCustomer,
            );
        }

        $result->integration_customer = $integrationCustomer->refresh();

        return $result;
    }

    /** Rails: customer.partner_account? is false and type not non-syncing. */
    private function syncProviderCustomer(IntegrationCustomer $integrationCustomer): bool
    {
        if ($integrationCustomer->customer?->partnerAccount()) {
            return false;
        }

        return ! in_array($integrationCustomer->type, self::NON_SYNCING_TYPES, true);
    }
}
