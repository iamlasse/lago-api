<?php

declare(strict_types=1);

namespace App\Services\IntegrationCustomers;

use App\Models\Customer;
use App\Models\Integration;
use App\Services\BaseResult;
use App\Models\IntegrationCustomer;
use App\Services\Integrations\Aggregator\Contacts\CreateService as ContactsCreateService;

/**
 * Port of Rails' IntegrationCustomers::AvalaraService
 * (app/services/integration_customers/avalara_service.rb) — the Avalara
 * contact is created through the Nango contacts call; the returned contact
 * id becomes the integration customer's external id.
 */
class AvalaraService extends \App\Services\BaseService
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

        $createResult = ContactsCreateService::call(
            integration: $this->integration,
            customer: $this->customer,
            subsidiary_id: null,
        );

        if ($createResult->failure() || $createResult->contact_id === null) {
            return $createResult;
        }

        $newIntegrationCustomer = new IntegrationCustomer([
            'organization_id' => $this->integration->organization_id,
            'integration_id' => $this->integration->id,
            'customer_id' => $this->customer->id,
            'external_customer_id' => $createResult->contact_id,
            'type' => IntegrationCustomer::AVALARA_TYPE,
            'category' => IntegrationCustomer::CATEGORIES['tax'],
            'settings' => ['sync_with_provider' => true],
        ]);
        $newIntegrationCustomer->setRelation('integration', $this->integration);
        $newIntegrationCustomer->setRelation('customer', $this->customer);
        $newIntegrationCustomer->save();

        $result->integration_customer = $newIntegrationCustomer;

        return $result;
    }
}
