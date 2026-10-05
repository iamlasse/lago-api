<?php

declare(strict_types=1);

namespace App\Services\IntegrationCustomers;

use App\Models\Customer;
use App\Models\Integration;
use App\Services\BaseResult;
use App\Models\IntegrationCustomer;

/**
 * Port of Rails' IntegrationCustomers::CreateService
 * (app/services/integration_customers/create_service.rb) — an explicit
 * external_customer_id links the row, a sync_with_provider flag syncs it
 * through the provider; the code/is_default routing attributes are stamped
 * afterwards.
 */
class CreateService extends BaseService
{
    /** The service result the parent class builds (rails' `result` reader). */
    private BaseResult $serviceResult;

    public function __construct(
        /** @var array<string, mixed> */
        array $params,
        ?Integration $integration,
        public readonly ?Customer $customer,
    ) {
        parent::__construct($params, $integration);
    }

    public function execute(): BaseResult
    {
        $result = $this->serviceResult = parent::execute();

        if ($result->failure()) {
            return $result;
        }

        $res = null;

        if ($this->external_customer_id() !== null) {
            $res = $this->link_customer();
        } elseif ($this->sync_with_provider()) {
            $res = $this->sync_customer();
        }

        if ($res?->failure()) {
            return $res;
        }

        if ($result->integration_customer !== null) {
            $this->assign_routing_attributes($result->integration_customer);
        }

        return $result;
    }

    private function sync_customer(): ?BaseResult
    {
        $integrationCustomerService = Factory::new_instance(
            integration: $this->integration,
            customer: $this->customer,
            subsidiary_id: $this->subsidiary_id(),
            params: $this->params,
        );

        $syncResult = $integrationCustomerService->create();

        if ($syncResult->failure()) {
            return $syncResult;
        }

        $this->serviceResult->integration_customer = $syncResult->integration_customer;

        return $syncResult;
    }

    private function link_customer(): ?BaseResult
    {
        $type = $this->customer_type();
        $class = match ($type) {
            IntegrationCustomer::ANROK_TYPE => \App\Models\IntegrationCustomers\AnrokCustomer::class,
            IntegrationCustomer::AVALARA_TYPE => \App\Models\IntegrationCustomers\AvalaraCustomer::class,
            IntegrationCustomer::HUBSPOT_TYPE => \App\Models\IntegrationCustomers\HubspotCustomer::class,
            IntegrationCustomer::SALESFORCE_TYPE => \App\Models\IntegrationCustomers\SalesforceCustomer::class,
            IntegrationCustomer::XERO_TYPE => \App\Models\IntegrationCustomers\XeroCustomer::class,
            IntegrationCustomer::NETSUITE_TYPE => \App\Models\IntegrationCustomers\NetsuiteCustomer::class,
            default => \App\Models\IntegrationCustomers\AvalaraCustomer::class,
        };

        // Rails: only the Salesforce link re-syncs with the provider on the
        // next document sync (sync_with_provider stays true).
        $syncWithProvider = $this->integration->type === Integration::SALESFORCE_TYPE;

        $newIntegrationCustomer = new $class([
            'organization_id' => $this->integration->organization_id,
            'integration_id' => $this->integration->id,
            'customer_id' => $this->customer->id,
            'external_customer_id' => $this->external_customer_id(),
            'type' => $this->customer_type(),
            'category' => $this->categoryFor($type),
            'settings' => ['sync_with_provider' => $syncWithProvider],
        ]);

        // Rails: the Netsuite link carries the subsidiary (settings).
        if ($this->integration->type === Integration::NETSUITE_TYPE) {
            $newIntegrationCustomer->settings = array_merge(
                (array) ($newIntegrationCustomer->settings ?? []),
                ['subsidiary_id' => $this->subsidiary_id()],
            );
        }

        // Rails: the Hubspot link carries the targeted object (settings).
        if ($this->integration->type === Integration::HUBSPOT_TYPE) {
            $newIntegrationCustomer->settings = array_merge(
                (array) ($newIntegrationCustomer->settings ?? []),
                ['targeted_object' => $this->targeted_object()],
            );
        }

        $newIntegrationCustomer->setRelation('integration', $this->integration);
        $newIntegrationCustomer->setRelation('customer', $this->customer);
        $newIntegrationCustomer->save();

        $this->serviceResult->integration_customer = $newIntegrationCustomer;

        return null;
    }

    /** Rails: BaseCustomer.category_for(customer_type). */
    private function categoryFor(string $type): string
    {
        return IntegrationCustomer::CATEGORY_BY_TYPE[$type]
            ?? IntegrationCustomer::CATEGORIES['tax'];
    }

    private function assign_routing_attributes(IntegrationCustomer $integrationCustomer): void
    {
        if ($this->code() !== null) {
            $integrationCustomer->code = $this->code();
        }

        $integrationCustomer->is_default = $this->first_connection_for_category($integrationCustomer);

        if ($integrationCustomer->isDirty()) {
            $integrationCustomer->save();
        }
    }

    private function first_connection_for_category(IntegrationCustomer $integrationCustomer): bool
    {
        return ! $this->customer->integrationCustomers()
            ->where('category', $integrationCustomer->category)
            ->where('id', '!=', $integrationCustomer->id)
            ->exists();
    }
}
