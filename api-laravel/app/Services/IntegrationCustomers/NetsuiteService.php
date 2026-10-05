<?php

declare(strict_types=1);

namespace App\Services\IntegrationCustomers;

use App\Models\Customer;
use App\Models\Integration;
use App\Services\BaseResult;
use App\Models\IntegrationCustomer;
use App\Models\IntegrationCustomers\NetsuiteCustomer;
use App\Services\Integrations\Aggregator\Contacts\CreateService as ContactsCreateService;

/**
 * Port of Rails' IntegrationCustomers::NetsuiteService
 * (app/services/integration_customers/netsuite_service.rb) — idempotent on
 * the (customer, integration) pair: an existing Netsuite integration
 * customer is returned as-is instead of a second Nango contact.
 */
class NetsuiteService extends \App\Services\BaseService
{
    public function __construct(
        public readonly Integration $integration,
        public readonly Customer $customer,
        public readonly ?string $subsidiary_id,
        /** @var array<string, mixed> */
        public readonly array $params = [],
    ) {
        parent::__construct();
    }

    /** Rails only ever drives #create (the sync jobs / factory do too). */
    public function execute(): BaseResult
    {
        return $this->create();
    }

    public function create(): BaseResult
    {
        $result = BaseResult::of('integration_customer');

        $existing = $this->existing_integration_customer();

        if ($existing !== null) {
            $result->integration_customer = $existing;

            return $result;
        }

        $createResult = ContactsCreateService::call(
            integration: $this->integration,
            customer: $this->customer,
            subsidiary_id: $this->subsidiary_id,
        );

        if ($createResult->failure() || $createResult->contact_id === null) {
            return $createResult;
        }

        $newIntegrationCustomer = new NetsuiteCustomer([
            'organization_id' => $this->integration->organization_id,
            'integration_id' => $this->integration->id,
            'customer_id' => $this->customer->id,
            'external_customer_id' => $createResult->contact_id,
            'type' => IntegrationCustomer::NETSUITE_TYPE,
            'category' => IntegrationCustomer::CATEGORIES['accounting'],
            'settings' => [
                'sync_with_provider' => true,
                'subsidiary_id' => $this->subsidiary_id,
            ],
        ]);
        $newIntegrationCustomer->setRelation('integration', $this->integration);
        $newIntegrationCustomer->setRelation('customer', $this->customer);
        $newIntegrationCustomer->save();

        $result->integration_customer = $newIntegrationCustomer;

        return $result;
    }

    private function existing_integration_customer(): ?IntegrationCustomer
    {
        return IntegrationCustomer::query()
            ->where('customer_id', $this->customer->id)
            ->where('integration_id', $this->integration->id)
            ->where('type', IntegrationCustomer::NETSUITE_TYPE)
            ->first();
    }
}
