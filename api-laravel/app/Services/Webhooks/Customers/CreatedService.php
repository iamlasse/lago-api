<?php

declare(strict_types=1);

namespace App\Services\Webhooks\Customers;

use App\Serializers\V1\CustomerSerializer;
use App\Services\Webhooks\BaseService;

/**
 * Port of Rails' Webhooks::Customers::CreatedService
 * (app/services/webhooks/customers/created_service.rb).
 */
class CreatedService extends BaseService
{
    /** @return array<string, mixed> */
    protected function objectSerializer(): array
    {
        return (new CustomerSerializer(
            $this->object,
            ['root_name' => 'customer', 'includes' => ['integration_customers']],
        ))->serialize();
    }

    protected function webhookType(): string
    {
        return 'customer.created';
    }

    protected function objectType(): string
    {
        return 'customer';
    }
}
