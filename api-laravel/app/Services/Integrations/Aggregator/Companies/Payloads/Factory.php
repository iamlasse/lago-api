<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\Companies\Payloads;

use LogicException;
use App\Models\Customer;
use App\Models\Integration;
use App\Models\IntegrationCustomer;

/**
 * Port of Rails' Integrations::Aggregator::Companies::Payloads::Factory —
 * only HubSpot deploys company objects today.
 */
final class Factory
{
    public static function new_instance(
        Integration $integration,
        ?IntegrationCustomer $integration_customer,
        Customer $customer,
        ?string $subsidiary_id,
    ): Hubspot {
        return match ($integration->type) {
            Integration::HUBSPOT_TYPE => new Hubspot(
                integration: $integration,
                customer: $customer,
                integration_customer: $integration_customer,
                subsidiary_id: $subsidiary_id,
            ),
            default => throw new LogicException('NotImplementedError'),
        };
    }
}
