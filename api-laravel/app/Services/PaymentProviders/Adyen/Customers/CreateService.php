<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Adyen\Customers;

use App\Models\PaymentProviderCustomer;
use App\Jobs\PaymentProviders\AdyenCheckoutUrlJob;
use App\Jobs\PaymentProviders\AdyenCreateCustomerJob;
use App\Services\PaymentProviders\AbstractCustomersCreateService;

/**
 * Port of Rails' PaymentProviders::Adyen::Customers::CreateService — the
 * shared connection shape (see AbstractCustomersCreateService) with the
 * Adyen create / checkout-url jobs.
 */
class CreateService extends AbstractCustomersCreateService
{
    protected function providerCustomerType(): string
    {
        return 'PaymentProviderCustomers::AdyenCustomer';
    }

    protected function createOnProvider(PaymentProviderCustomer $providerCustomer, bool $async): void
    {
        if ($async) {
            dispatch(new \App\Jobs\PaymentProviders\AdyenCreateCustomerJob($providerCustomer));

            return;
        }

        dispatch_sync(new \App\Jobs\PaymentProviders\AdyenCreateCustomerJob($providerCustomer));
    }

    protected function generateCheckoutUrl(PaymentProviderCustomer $providerCustomer, bool $async): void
    {
        if ($async) {
            dispatch(new \App\Jobs\PaymentProviders\AdyenCheckoutUrlJob($providerCustomer));

            return;
        }

        dispatch_sync(new \App\Jobs\PaymentProviders\AdyenCheckoutUrlJob($providerCustomer));
    }
}
