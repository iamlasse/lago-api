<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\Invoices\Payloads;

use LogicException;
use App\Models\Invoice;
use App\Models\IntegrationCustomer;

/**
 * Port of Rails' Integrations::Aggregator::Invoices::Payloads::Factory —
 * the accounting-provider payload dispatch.
 */
final class Factory
{
    public static function new_instance(
        ?IntegrationCustomer $integration_customer,
        Invoice $invoice,
    ): BasePayload {
        return match ($integration_customer?->integration?->type) {
            'Integrations::NetsuiteIntegration' => new Netsuite(
                integration_customer: $integration_customer,
                invoice: $invoice,
            ),
            'Integrations::XeroIntegration' => new Xero(
                integration_customer: $integration_customer,
                invoice: $invoice,
            ),
            'Integrations::HubspotIntegration' => new Hubspot(
                integration_customer: $integration_customer,
                invoice: $invoice,
            ),
            default => throw new LogicException('NotImplementedError'),
        };
    }
}
