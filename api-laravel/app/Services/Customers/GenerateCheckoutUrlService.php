<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Models\Customer;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\PaymentProviderCustomers\Factory;

/**
 * Port of Rails' Customers::GenerateCheckoutUrlService
 * (app/services/customers/generate_checkout_url_service.rb) — the checkout
 * URL entrypoint behind the generateCheckoutUrl mutation: resolves the
 * customer's own provider connection and hands it to the per-provider
 * PaymentProviderCustomers service's generate_checkout_url action (with the
 * webhook suppressed, like Rails).
 */
class GenerateCheckoutUrlService extends BaseService
{
    public function __construct(
        private readonly ?Customer $customer,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('checkout_url');

        if ($this->customer === null) {
            return $result->notFoundFailure('customer');
        }

        $providerCustomer = $this->providerCustomer();

        if ($providerCustomer === null) {
            return $result->singleValidationFailure('no_linked_payment_provider');
        }

        $serviceClass = Factory::for($providerCustomer);

        // TODO(port): Rails passes send_webhook: false here (the mutation
        // path must not re-announce the URL); the ported per-provider
        // generate_checkout_url actions always deliver the webhook.
        return $serviceClass::call(
            action: 'generate_checkout_url',
            providerCustomer: $providerCustomer,
        );
    }

    /**
     * Rails: `customer&.provider_customer` — the connection of the
     * customer's active provider slug.
     */
    private function providerCustomer(): ?\App\Models\PaymentProviderCustomer
    {
        $customer = $this->customer;

        if ($customer === null || $customer->payment_provider === null || $customer->payment_provider === '') {
            return null;
        }

        return $customer->paymentProviderCustomers()
            ->whereIn('type', [
                'PaymentProviderCustomers::StripeCustomer',
                'PaymentProviderCustomers::AdyenCustomer',
                'PaymentProviderCustomers::GocardlessCustomer',
                'PaymentProviderCustomers::CashfreeCustomer',
                'PaymentProviderCustomers::FlutterwaveCustomer',
                'PaymentProviderCustomers::MoneyhashCustomer',
            ])
            ->get()
            ->first(fn (\App\Models\PaymentProviderCustomer $row): bool
                => Factory::providerSlug($row) === $customer->payment_provider);
    }
}
