<?php

declare(strict_types=1);

namespace App\Services\IntegrationCustomers;

use App\Models\Customer;
use App\Models\Integration;
use App\Services\BaseResult;
use App\Models\IntegrationCustomer;
use App\Models\IntegrationCustomers\HubspotCustomer;
use App\Services\Integrations\Aggregator\Contacts\CreateService as ContactsCreateService;
use App\Services\Integrations\Aggregator\Companies\CreateService as CompaniesCreateService;

/**
 * Port of Rails' IntegrationCustomers::HubspotService
 * (app/services/integration_customers/hubspot_service.rb) — the CRM object
 * is created through the Nango contacts or companies call (by targeted
 * object); the returned id becomes the integration customer's external id.
 */
class HubspotService extends \App\Services\BaseService
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

        $createResult = $this->create_service_class()::call(
            integration: $this->integration,
            customer: $this->customer,
            subsidiary_id: null,
        );

        if ($createResult->failure() || $createResult->contact_id === null) {
            return $createResult;
        }

        $newIntegrationCustomer = new HubspotCustomer([
            'organization_id' => $this->integration->organization_id,
            'integration_id' => $this->integration->id,
            'customer_id' => $this->customer->id,
            'external_customer_id' => $createResult->contact_id,
            'type' => IntegrationCustomer::HUBSPOT_TYPE,
            'category' => IntegrationCustomer::CATEGORIES['crm'],
            'settings' => [
                'sync_with_provider' => true,
                'targeted_object' => $this->targeted_object(),
                'email' => $createResult->email,
            ],
        ]);
        $newIntegrationCustomer->setRelation('integration', $this->integration);
        $newIntegrationCustomer->setRelation('customer', $this->customer);
        $newIntegrationCustomer->save();

        $result->integration_customer = $newIntegrationCustomer;

        return $result;
    }

    /** Rails: `create_service_class` — the collector per the targeted object. */
    private function create_service_class(): string
    {
        return $this->targeted_object() === 'contacts'
            ? ContactsCreateService::class
            : CompaniesCreateService::class;
    }

    /**
     * Rails: `targeted_object` — the params value, else the individual
     * customer's default, else the company customer's default, else the
     * integration's configured default.
     */
    private function targeted_object(): ?string
    {
        // customer_type may come back as a backed enum (Customer model cast).
        $customerType = $this->customer->customer_type;
        $typeValue = is_object($customerType) ? ($customerType->value ?? null) : $customerType;

        return $this->params['targeted_object'] ?? null
            ?: ($typeValue === 'individual' ? 'contacts' : null)
            ?: ($typeValue === 'company' ? 'companies' : null)
            ?: $this->integration->getFromSettings('default_targeted_object');
    }
}
