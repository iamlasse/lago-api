<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Moneyhash\Customers;

use App\Models\PaymentProviderCustomer;
use App\Jobs\PaymentProviders\MoneyhashCheckoutUrlJob;
use App\Jobs\PaymentProviders\MoneyhashCreateCustomerJob;
use App\Services\PaymentProviders\AbstractCustomersCreateService;

/**
 * Port of Rails' PaymentProviders::Moneyhash::Customers::CreateService —
 * the shared connection shape (see AbstractCustomersCreateService) with the
 * Moneyhash create / checkout-url jobs.
 */
class CreateService extends AbstractCustomersCreateService
{
    protected function providerCustomerType(): string
    {
        return 'PaymentProviderCustomers::MoneyhashCustomer';
    }

    protected function createOnProvider(PaymentProviderCustomer $providerCustomer, bool $async): void
    {
        if ($async) {
            dispatch(new \App\Jobs\PaymentProviders\MoneyhashCreateCustomerJob($providerCustomer));

            return;
        }

        dispatch_sync(new \App\Jobs\PaymentProviders\MoneyhashCreateCustomerJob($providerCustomer));
    }

    protected function generateCheckoutUrl(PaymentProviderCustomer $providerCustomer, bool $async): void
    {
        if ($async) {
            dispatch(new \App\Jobs\PaymentProviders\MoneyhashCheckoutUrlJob($providerCustomer));

            return;
        }

        dispatch_sync(new \App\Jobs\PaymentProviders\MoneyhashCheckoutUrlJob($providerCustomer));
    }
}
