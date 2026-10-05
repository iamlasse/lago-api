<?php

declare(strict_types=1);

namespace App\Services\Invoices\Payments\PaymentProviders;

use LogicException;
use App\Services\Invoices\Payments\AdyenService;
use App\Services\Invoices\Payments\StripeService;
use App\Services\Invoices\Payments\CashfreeService;
use App\Services\Invoices\Payments\MoneyhashService;
use App\Services\Invoices\Payments\GocardlessService;
use App\Services\Invoices\Payments\FlutterwaveService;

/**
 * Port of Rails' Invoices::Payments::PaymentProviders::Factory — picks the
 * per-provider service exposing `generate_payment_url` for the invoice's
 * customer payment provider.
 */
class Factory
{
    /** @return class-string */
    public static function for(\App\Models\Invoice $invoice): string
    {
        return self::serviceClass($invoice->customer?->payment_provider);
    }

    /** @return class-string */
    public static function serviceClass(?string $paymentProvider): string
    {
        return match ($paymentProvider) {
            'stripe' => StripeService::class,
            'adyen' => AdyenService::class,
            'gocardless' => GocardlessService::class,
            'cashfree' => CashfreeService::class,
            'flutterwave' => FlutterwaveService::class,
            'moneyhash' => MoneyhashService::class,
            default => throw new LogicException('Payment provider is not supported'),
        };
    }
}
