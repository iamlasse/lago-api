<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\Contacts\Payloads;

use LogicException;
use App\Models\Customer;
use App\Models\Integration;
use App\Models\IntegrationCustomer;

/**
 * Port of Rails' Integrations::Aggregator::Contacts::Payloads::Factory —
 * the tax-provider legs (anrok, avalara); the accounting/CRM payload
 * builders arrive with their own slices.
 */
final class Factory
{
    public static function new_instance(
        Integration $integration,
        ?IntegrationCustomer $integration_customer,
        Customer $customer,
        ?string $subsidiary_id,
    ): BasePayload {
        return match ($integration->type) {
            Integration::ANROK_TYPE => new Anrok(
                integration: $integration,
                customer: $customer,
                integration_customer: $integration_customer,
                subsidiary_id: $subsidiary_id,
            ),
            Integration::AVALARA_TYPE => new Avalara(
                integration: $integration,
                customer: $customer,
                integration_customer: $integration_customer,
                subsidiary_id: $subsidiary_id,
            ),
            default => throw new LogicException('NotImplementedError'),
        };
    }
}
