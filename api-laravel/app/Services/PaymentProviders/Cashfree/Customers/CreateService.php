<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Cashfree\Customers;

use App\Services\PaymentProviders\AbstractCustomersCreateService;

/**
 * Port of Rails' PaymentProviders::Cashfree::Customers::CreateService —
 * local connection only: the sync_with_provider setting is stored, but
 * Cashfree has no remote customer record (and no checkout URL support).
 */
class CreateService extends AbstractCustomersCreateService
{
    protected function providerCustomerType(): string
    {
        return 'PaymentProviderCustomers::CashfreeCustomer';
    }
}
