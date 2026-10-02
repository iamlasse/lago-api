<?php

declare(strict_types=1);

namespace App\Services\Webhooks\Customers;

use App\Services\Webhooks\BaseService;
use App\Serializers\V1\CustomerSerializer;

/**
 * Port of Rails' Webhooks::Customers::UpdatedService
 * (app/services/webhooks/customers/updated_service.rb).
 */
class UpdatedService extends BaseService
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
        return 'customer.updated';
    }

    protected function objectType(): string
    {
        return 'customer';
    }
}
