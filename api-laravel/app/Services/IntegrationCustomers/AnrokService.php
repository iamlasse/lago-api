<?php

declare(strict_types=1);

namespace App\Services\IntegrationCustomers;

use App\Models\Customer;
use App\Models\Integration;
use App\Services\BaseResult;
use App\Models\IntegrationCustomer;

/**
 * Port of Rails' IntegrationCustomers::AnrokService
 * (app/services/integration_customers/anrok_service.rb) — for Anrok the real
 * customer sync happens with the first document sync; in the meantime the
 * integration customer row is stored on the Lago side.
 */
class AnrokService extends \App\Services\BaseService
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

        $newIntegrationCustomer = new IntegrationCustomer([
            'organization_id' => $this->integration->organization_id,
            'integration_id' => $this->integration->id,
            'customer_id' => $this->customer->id,
            'type' => IntegrationCustomer::ANROK_TYPE,
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
