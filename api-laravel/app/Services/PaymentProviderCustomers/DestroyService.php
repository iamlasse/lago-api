<?php

declare(strict_types=1);

namespace App\Services\PaymentProviderCustomers;

use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\PaymentMethod;
use App\Models\PaymentProviderCustomer;
use App\Services\PaymentMethods\DestroyService as DestroyPaymentMethodService;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' PaymentProviderCustomers::DestroyService
 * (app/services/payment_provider_customers/destroy_service.rb) — soft-deletes
 * the connection, destroys its payment methods and billing object
 * connections, and — when the destroyed connection is the customer's active
 * provider — clears the customer payment_provider pointers.
 */
class DestroyService extends BaseService
{
    public function __construct(
        private readonly ?PaymentProviderCustomer $paymentProviderCustomer,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('payment_provider_customer');

        if ($this->paymentProviderCustomer === null) {
            return $result->notFoundFailure('payment_provider_customer');
        }

        DB::transaction(function (): void {
            $this->paymentProviderCustomer->is_default = false;
            $this->paymentProviderCustomer->delete();

            // Rails: payment_provider_customer.payment_methods.find_each.
            PaymentMethod::query()
                ->where('payment_provider_customer_id', $this->paymentProviderCustomer->id)
                ->get()
                ->each(function ($paymentMethod): void {
                    DestroyPaymentMethodService::call(paymentMethod: $paymentMethod)->raiseIfError();
                });

            // Rails: billing_object_connections.destroy_all — the BillingObjectConnection
            // model has no ported consumers yet (TODO(port) with that slice).

            $this->clearCustomerPaymentProvider();
        });

        $result->payment_provider_customer = $this->paymentProviderCustomer;

        return $result;
    }

    /**
     * Rails: clear_customer_payment_provider — keep the customer in the same
     * end state as removing the provider through Customers::UpdateService.
     */
    private function clearCustomerPaymentProvider(): void
    {
        $customer = $this->paymentProviderCustomer->customer;

        if ($customer === null || $customer->payment_provider !== Factory::providerSlug($this->paymentProviderCustomer)) {
            return;
        }

        $customer->payment_provider = null;
        $customer->payment_provider_code = null;
        $customer->save();
    }
}
