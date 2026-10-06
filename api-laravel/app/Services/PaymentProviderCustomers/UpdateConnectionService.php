<?php

declare(strict_types=1);

namespace App\Services\PaymentProviderCustomers;

use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\PaymentProviderCustomer;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' PaymentProviderCustomers::UpdateConnectionService
 * (app/services/payment_provider_customers/update_connection_service.rb) —
 * "Updates a payment provider customer connection": the connection code, and
 * — when the provider-customer editing keys are present — the provider-side
 * record through the per-provider create-or-update. An explicit
 * provider_customer_id re-runs the provider sync afterwards.
 */
class UpdateConnectionService extends BaseService
{
    public function __construct(
        private readonly ?PaymentProviderCustomer $paymentProviderCustomer,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('payment_provider_customer');

        if ($this->paymentProviderCustomer === null) {
            return $result->notFoundFailure('payment_provider_customer');
        }

        $editingProviderCustomer = array_key_exists('provider_customer_id', $this->params)
            || array_key_exists('provider_payment_methods', $this->params)
            || array_key_exists('sync_with_provider', $this->params);

        try {
            DB::transaction(function () use ($editingProviderCustomer): void {
                if (array_key_exists('code', $this->params)) {
                    $this->paymentProviderCustomer->code = $this->params['code'];
                    $this->paymentProviderCustomer->save();
                }

                if ($editingProviderCustomer) {
                    $this->updateProviderCustomer();
                }
            });

            if (($this->params['provider_customer_id'] ?? null) !== null
                && $this->params['provider_customer_id'] !== '') {
                $this->syncProviderCustomer();
            }
        } catch (\Throwable $e) {
            // Rails: rescue BaseService::FailedResult -> e.result.
            if ($e instanceof \App\Services\Failures\FailedResult) {
                return $result->failWithError($e);
            }

            throw $e;
        }

        $result->payment_provider_customer = $this->paymentProviderCustomer->refresh();

        return $result;
    }

    /**
     * Rails: PaymentProviders::CreateCustomerFactory.new_instance(provider:, ...)
     * — the per-provider create-or-update against the connection's provider.
     */
    private function updateProviderCustomer(): void
    {
        $slug = Factory::providerSlug($this->paymentProviderCustomer);

        $serviceClass = match ($slug) {
            'stripe' => \App\Services\PaymentProviders\Stripe\Customers\CreateOrUpdateService::class,
            'adyen' => \App\Services\PaymentProviders\Adyen\Customers\CreateService::class,
            'gocardless' => \App\Services\PaymentProviders\Gocardless\Customers\CreateService::class,
            'cashfree' => \App\Services\PaymentProviders\Cashfree\Customers\CreateService::class,
            'flutterwave' => \App\Services\PaymentProviders\Flutterwave\Customers\CreateService::class,
            'moneyhash' => \App\Services\PaymentProviders\Moneyhash\Customers\CreateService::class,
            default => null,
        };

        if ($serviceClass === null) {
            throw new \LogicException("Payment provider customer type '{$this->paymentProviderCustomer->type}' is not supported yet");
        }

        $serviceClass::call(
            customer: $this->paymentProviderCustomer->customer,
            paymentProviderId: $this->paymentProviderCustomer->paymentProvider?->id,
            params: [
                'provider_customer_id' => $this->params['provider_customer_id'] ?? null,
                'provider_payment_methods' => $this->params['provider_payment_methods'] ?? null,
                'sync_with_provider' => $this->params['sync_with_provider'] ?? null,
            ],
        )->raiseIfError();

        $this->paymentProviderCustomer->customer->refresh();
    }

    /**
     * Rails: PaymentProviderCustomers::UpdateService.call(customer) — re-runs
     * the provider-side `update` action on the customer's own connection.
     */
    private function syncProviderCustomer(): void
    {
        $customer = $this->paymentProviderCustomer->customer;

        $providerCustomer = $customer !== null
            ? $customer->paymentProviderCustomers()->where('type', $this->paymentProviderCustomer->type)->first()
            : null;

        if ($providerCustomer === null) {
            return;
        }

        $serviceClass = Factory::for($providerCustomer);

        $serviceClass::call(
            action: 'update',
            providerCustomer: $providerCustomer,
        )->raiseIfError();
    }
}
