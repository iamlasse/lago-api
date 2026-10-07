<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\Payments\Payloads;

use LogicException;
use App\Models\Payment;
use App\Models\Integration;

/**
 * Port of Rails' Integrations::Aggregator::Payments::Payloads::Factory —
 * the accounting-provider payload dispatch.
 */
final class Factory
{
    public static function new_instance(
        Integration $integration,
        Payment $payment,
    ): BasePayload {
        return match ($integration->type) {
            'Integrations::NetsuiteIntegration' => new Netsuite(
                integration: $integration,
                payment: $payment,
            ),
            'Integrations::XeroIntegration' => new Xero(
                integration: $integration,
                payment: $payment,
            ),
            default => throw new LogicException('NotImplementedError'),
        };
    }
}
