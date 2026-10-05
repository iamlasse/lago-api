<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Flutterwave\Customers;

use App\Services\PaymentProviders\AbstractCustomersCreateService;

/**
 * Port of Rails' PaymentProviders::Flutterwave::Customers::CreateService —
 * local connection only: the sync_with_provider setting is stored, but
 * Flutterwave has no remote customer record (and no checkout URL support).
 */
class CreateService extends AbstractCustomersCreateService
{
    protected function providerCustomerType(): string
    {
        return 'PaymentProviderCustomers::FlutterwaveCustomer';
    }
}
