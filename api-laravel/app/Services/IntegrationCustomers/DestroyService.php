<?php

declare(strict_types=1);

namespace App\Services\IntegrationCustomers;

use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\IntegrationCustomer;

/**
 * Port of Rails' IntegrationCustomers::DestroyService
 * (app/services/integration_customers/destroy_service.rb).
 */
class DestroyService extends BaseService
{
    public function __construct(
        private readonly ?IntegrationCustomer $integration_customer,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('integration_customer');

        if ($this->integration_customer === null) {
            return $result->notFoundFailure('integration_customer');
        }

        $this->integration_customer->delete();

        $result->integration_customer = $this->integration_customer;

        return $result;
    }
}
