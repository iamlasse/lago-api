<?php

declare(strict_types=1);

namespace App\Services\IntegrationCustomers;

use LogicException;
use App\Models\Customer;
use App\Models\Integration;

/**
 * Port of Rails' IntegrationCustomers::Factory — the per-provider sync
 * service dispatch. The tax-provider legs are ported; the accounting/CRM
 * ones arrive with their own slices.
 */
final class Factory
{
    /**
     * @param  array<string, mixed>  $params
     */
    public static function new_instance(
        Integration $integration,
        Customer $customer,
        ?string $subsidiary_id,
        array $params = [],
    ): AnrokService|AvalaraService {
        return match ($integration->type) {
            Integration::ANROK_TYPE => new AnrokService(
                integration: $integration,
                customer: $customer,
                subsidiary_id: $subsidiary_id,
                params: $params,
            ),
            Integration::AVALARA_TYPE => new AvalaraService(
                integration: $integration,
                customer: $customer,
                subsidiary_id: $subsidiary_id,
                params: $params,
            ),
            default => throw new LogicException('NotImplementedError'),
        };
    }
}
