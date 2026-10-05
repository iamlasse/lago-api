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
            AdyenCreateCustomerJob::dispatch($providerCustomer);

            return;
        }

        AdyenCreateCustomerJob::dispatchSync($providerCustomer);
    }

    protected function generateCheckoutUrl(PaymentProviderCustomer $providerCustomer, bool $async): void
    {
        if ($async) {
            AdyenCheckoutUrlJob::dispatch($providerCustomer);

            return;
        }

        AdyenCheckoutUrlJob::dispatchSync($providerCustomer);
    }
}
