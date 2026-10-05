<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\Taxes\Invoices\Payloads;

use LogicException;
use App\Models\Invoice;
use App\Models\Customer;
use App\Models\Integration;
use App\Models\IntegrationCustomer;
use App\Services\Integrations\Aggregator\BasePayload;
use App\Services\Integrations\Aggregator\Taxes\Invoices\ChargeFeeGroup;

/**
 * Port of Rails' Integrations::Aggregator::Taxes::Invoices::Payloads::Factory
 * — the per-provider payload builder dispatch.
 */
final class Factory
{
    /**
     * @param  list<\App\Models\Fee|ChargeFeeGroup>  $fees
     */
    public static function new_instance(
        Integration $integration,
        Invoice $invoice,
        Customer $customer,
        ?IntegrationCustomer $integration_customer,
        array $fees,
    ): BasePayload {
        return match ($integration->type) {
            Integration::ANROK_TYPE => new Anrok(
                integration: $integration,
                customer: $customer,
                invoice: $invoice,
                integration_customer: $integration_customer,
                fees: $fees,
            ),
            Integration::AVALARA_TYPE => new Avalara(
                integration: $integration,
                customer: $customer,
                invoice: $invoice,
                integration_customer: $integration_customer,
                fees: $fees,
            ),
            default => throw new LogicException('NotImplementedError'),
        };
    }
}
