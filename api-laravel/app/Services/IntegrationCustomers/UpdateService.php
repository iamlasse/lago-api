<?php

declare(strict_types=1);

namespace App\Services\IntegrationCustomers;

use App\Models\Integration;
use App\Services\BaseResult;
use App\Models\IntegrationCustomer;
use App\Services\Integrations\Aggregator\Contacts\UpdateService as ContactsUpdateService;

/**
 * Port of Rails' IntegrationCustomers::UpdateService
 * (app/services/integration_customers/update_service.rb) — the routing
 * attributes are updated locally; the provider contact is re-synced when an
 * external id is known (Anrok and Salesforce rows never reach the provider).
 */
class UpdateService extends BaseService
{
    public function __construct(
        /** @var array<string, mixed> */
        array $params,
        ?Integration $integration,
        public readonly ?IntegrationCustomer $integration_customer,
    ) {
        parent::__construct($params, $integration);
    }

    public function execute(): BaseResult
    {
        $result = parent::execute();

        if ($result->failure()) {
            return $result;
        }

        if ($this->integration_customer === null) {
            return $result->notFoundFailure('integration_customer');
        }

        $integrationCustomer = $this->integration_customer;

        if ($this->code() !== null) {
            $integrationCustomer->code = $this->code();
        }

        if ($integrationCustomer->isDirty()) {
            $integrationCustomer->save();
        }

        if ($integrationCustomer->type === IntegrationCustomer::ANROK_TYPE) {
            $result->integration_customer = $integrationCustomer;

            return $result;
        }

        // Rails: the Salesforce row never reaches the provider on update
        // either — the account/contact sync rides on the document collectors.
        if ($integrationCustomer->type === IntegrationCustomer::SALESFORCE_TYPE) {
            $result->integration_customer = $integrationCustomer;

            return $result;
        }

        if ($this->external_customer_id() !== null) {
            $integrationCustomer->external_customer_id = $this->external_customer_id();
        }

        if ($this->targeted_object() !== null) {
            $integrationCustomer->settings = array_merge(
                (array) ($integrationCustomer->settings ?? []),
                ['targeted_object' => $this->targeted_object()],
            );
        }

        $integrationCustomer->save();

        if ($integrationCustomer->external_customer_id !== null) {
            $updateServiceClass = $this->update_service_class($integrationCustomer);

            $updateResult = $updateServiceClass::call(
                integration: $this->integration,
                integration_customer: $integrationCustomer,
            );

            if ($updateResult->failure()) {
                return $updateResult;
            }
        }

        $result->integration_customer = $integrationCustomer;

        return $result;
    }

    /**
     * Rails: `update_service_class` — the collector per the integration
     * customer type and the targeted object.
     */
    private function update_service_class(IntegrationCustomer $integrationCustomer): string
    {
        if ($integrationCustomer->type !== IntegrationCustomer::HUBSPOT_TYPE) {
            return ContactsUpdateService::class;
        }

        return $integrationCustomer->targetedObject() === 'contacts'
            ? ContactsUpdateService::class
            : \App\Services\Integrations\Aggregator\Companies\UpdateService::class;
    }
}
