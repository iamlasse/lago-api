<?php

declare(strict_types=1);

namespace App\Services\PaymentProviderCustomers;

use LogicException;
use App\Models\PaymentProviderCustomer;
use App\Services\PaymentProviderCustomers\AdyenService;
use App\Services\PaymentProviderCustomers\CashfreeService;
use App\Services\PaymentProviderCustomers\FlutterwaveService;
use App\Services\PaymentProviderCustomers\GocardlessService;
use App\Services\PaymentProviderCustomers\MoneyhashService;
use App\Services\PaymentProviderCustomers\StripeService;

/**
 * Port of Rails' PaymentProviderCustomers::Factory
 * (app/services/payment_provider_customers/factory.rb) — dispatches the
 * per-provider PaymentProviderCustomers service for a connection row.
 */
class Factory
{
    /** Rails: `Factory.for(provider_customer)` — the STI type to service class map. */
    public static function forClass(?string $type): string
    {
        return match ($type) {
            'PaymentProviderCustomers::StripeCustomer' => StripeService::class,
            'PaymentProviderCustomers::GocardlessCustomer' => GocardlessService::class,
            'PaymentProviderCustomers::CashfreeCustomer' => CashfreeService::class,
            'PaymentProviderCustomers::FlutterwaveCustomer' => FlutterwaveService::class,
            'PaymentProviderCustomers::AdyenCustomer' => AdyenService::class,
            'PaymentProviderCustomers::MoneyhashCustomer' => MoneyhashService::class,
            default => throw new LogicException("Payment provider customer type '{$type}' is not supported yet"),
        };
    }

    /** Convenience over `forClass` for a loaded connection row. */
    public static function for(PaymentProviderCustomer $providerCustomer): string
    {
        return static::forClass($providerCustomer->type);
    }

    /**
     * Rails: the customer-facing provider slug behind a connection row
     * (`object.type.demodulize.underscore.delete_suffix("_customer")`).
     */
    public static function providerSlug(?PaymentProviderCustomer $providerCustomer): ?string
    {
        $type = $providerCustomer?->type;

        if ($type === null || $type === '') {
            return null;
        }

        $slug = match ($type) {
            'PaymentProviderCustomers::StripeCustomer' => 'stripe',
            'PaymentProviderCustomers::GocardlessCustomer' => 'gocardless',
            'PaymentProviderCustomers::CashfreeCustomer' => 'cashfree',
            'PaymentProviderCustomers::FlutterwaveCustomer' => 'flutterwave',
            'PaymentProviderCustomers::AdyenCustomer' => 'adyen',
            'PaymentProviderCustomers::MoneyhashCustomer' => 'moneyhash',
            default => null,
        };

        return $slug;
    }
}
