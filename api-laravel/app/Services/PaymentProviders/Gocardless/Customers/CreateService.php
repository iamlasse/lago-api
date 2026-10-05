<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Gocardless\Customers;

use App\Models\PaymentProviderCustomer;
use App\Jobs\PaymentProviders\GocardlessCheckoutUrlJob;
use App\Jobs\PaymentProviders\GocardlessCreateCustomerJob;
use App\Services\PaymentProviders\AbstractCustomersCreateService;

/**
 * Port of Rails' PaymentProviders::Gocardless::Customers::CreateService —
 * the shared connection shape (see AbstractCustomersCreateService) with the
 * GoCardless create / checkout-url jobs.
 */
class CreateService extends AbstractCustomersCreateService
{
    protected function providerCustomerType(): string
    {
        return 'PaymentProviderCustomers::GocardlessCustomer';
    }

    protected function createOnProvider(PaymentProviderCustomer $providerCustomer, bool $async): void
    {
        if ($async) {
            GocardlessCreateCustomerJob::dispatch($providerCustomer);

            return;
        }

        GocardlessCreateCustomerJob::dispatchSync($providerCustomer);
    }

    protected function generateCheckoutUrl(PaymentProviderCustomer $providerCustomer, bool $async): void
    {
        if ($async) {
            GocardlessCheckoutUrlJob::dispatch($providerCustomer);

            return;
        }

        GocardlessCheckoutUrlJob::dispatchSync($providerCustomer);
    }
}
