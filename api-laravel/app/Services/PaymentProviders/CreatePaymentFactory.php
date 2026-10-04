<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders;

use LogicException;
use App\Models\Payment;
use App\Services\BaseService;
use App\Services\PaymentProviders\Stripe\Payments\CreateService as StripeCreateService;

/**
 * Port of Rails' PaymentProviders::CreatePaymentFactory — picks the
 * provider payment-creation service for a payment attempt.
 *
 * TODO(port) the remaining providers (Rails entry points):
 *  - adyen      -> PaymentProviders::Adyen::Payments::CreateService
 *  - cashfree   -> PaymentProviders::Cashfree::Payments::CreateService
 *  - gocardless -> PaymentProviders::Gocardless::Payments::CreateService
 *  - moneyhash  -> PaymentProviders::Moneyhash::Payments::CreateService
 * (flutterwave has no payments create service in Rails either.)
 */
class CreatePaymentFactory
{
    public static function newInstance(
        string $provider,
        Payment $payment,
        string $reference,
        array $metadata,
    ): BaseService {
        return match ($provider) {
            'stripe' => new StripeCreateService(payment: $payment, reference: $reference, metadata: $metadata),
            // TODO(port): adyen / cashfree / gocardless / moneyhash create
            // services (each calls its provider's charge/mandate API).
            default => throw new LogicException("Payment provider '{$provider}' is not supported yet"),
        };
    }
}
