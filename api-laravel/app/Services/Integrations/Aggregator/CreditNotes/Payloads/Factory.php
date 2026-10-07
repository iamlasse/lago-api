<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\CreditNotes\Payloads;

use LogicException;
use App\Models\CreditNote;
use App\Models\IntegrationCustomer;

/**
 * Port of Rails' Integrations::Aggregator::CreditNotes::Payloads::Factory —
 * the accounting-provider payload dispatch.
 */
final class Factory
{
    public static function new_instance(
        ?IntegrationCustomer $integration_customer,
        CreditNote $credit_note,
    ): BasePayload {
        return match ($integration_customer?->integration?->type) {
            'Integrations::NetsuiteIntegration' => new Netsuite(
                integration_customer: $integration_customer,
                credit_note: $credit_note,
            ),
            'Integrations::XeroIntegration' => new Xero(
                integration_customer: $integration_customer,
                credit_note: $credit_note,
            ),
            default => throw new LogicException('NotImplementedError'),
        };
    }
}
