<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders;

use LogicException;
use App\Models\Payment;
use App\Services\BaseService;
use App\Services\PaymentProviders\Stripe\Payments\CreateService as StripeCreateService;
use App\Services\PaymentProviders\Adyen\Payments\CreateService as AdyenCreateService;
use App\Services\PaymentProviders\Cashfree\Payments\CreateService as CashfreeCreateService;
use App\Services\PaymentProviders\Gocardless\Payments\CreateService as GocardlessCreateService;
use App\Services\PaymentProviders\Moneyhash\Payments\CreateService as MoneyhashCreateService;

/**
 * Port of Rails' PaymentProviders::CreatePaymentFactory — picks the
 * provider payment-creation service for a payment attempt.
 *
 * (flutterwave has no payments create service in Rails either — the
 * factory has no arm for it, so an attempt there is unsupported.)
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
            'adyen' => new AdyenCreateService(payment: $payment, reference: $reference, metadata: $metadata),
            'cashfree' => new CashfreeCreateService(payment: $payment),
            'gocardless' => new GocardlessCreateService(payment: $payment, reference: $reference, metadata: $metadata),
            'moneyhash' => new MoneyhashCreateService(payment: $payment, reference: $reference, metadata: $metadata),
            default => throw new LogicException("Payment provider '{$provider}' is not supported yet"),
        };
    }
}
