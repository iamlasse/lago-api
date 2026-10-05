<?php

declare(strict_types=1);

namespace App\Services\IntegrationCustomers;

use App\Models\Customer;
use App\Models\Integration;
use App\Services\BaseResult;
use App\Models\IntegrationCustomer;
use App\Models\IntegrationCustomers\SalesforceCustomer;

/**
 * Port of Rails' IntegrationCustomers::SalesforceService
 * (app/services/integration_customers/salesforce_service.rb) — Salesforce
 * doesn't need to reach a provider at creation: only the integration
 * customer row is stored (marked sync_with_provider), the account/contact
 * sync rides on the document collectors.
 */
class SalesforceService extends \App\Services\BaseService
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

        $newIntegrationCustomer = new SalesforceCustomer([
            'organization_id' => $this->integration->organization_id,
            'integration_id' => $this->integration->id,
            'customer_id' => $this->customer->id,
            'type' => IntegrationCustomer::SALESFORCE_TYPE,
            'category' => IntegrationCustomer::CATEGORIES['crm'],
            'settings' => ['sync_with_provider' => true],
        ]);
        $newIntegrationCustomer->setRelation('integration', $this->integration);
        $newIntegrationCustomer->setRelation('customer', $this->customer);
        $newIntegrationCustomer->save();

        $result->integration_customer = $newIntegrationCustomer;

        return $result;
    }
}
