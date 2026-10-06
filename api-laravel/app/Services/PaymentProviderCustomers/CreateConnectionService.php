<?php

declare(strict_types=1);

namespace App\Services\PaymentProviderCustomers;

use LogicException;
use App\Models\Customer;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Models\PaymentProviderCustomer;
use App\Services\PaymentProviders\FindService;

/**
 * Port of Rails' PaymentProviderCustomers::CreateConnectionService
 * (app/services/payment_provider_customers/create_connection_service.rb) —
 * "Creates a payment provider customer connection".
 *
 * Preconditions: customer present, payment_provider mandatory, and the args
 * must either sync with the provider or carry an explicit provider_customer_id
 * (otherwise Rails answers a bare success with no connection). The first
 * connection of a customer becomes the default and, while the customer has no
 * active provider, it becomes the customer's payment_provider.
 */
class CreateConnectionService extends BaseService
{
    public function __construct(
        private readonly ?Customer $customer,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('payment_provider_customer');

        if ($this->customer === null) {
            return $result->notFoundFailure('customer');
        }

        if (($this->params['payment_provider'] ?? null) === null || $this->params['payment_provider'] === '') {
            return $result->singleValidationFailure('value_is_mandatory', field: 'payment_provider');
        }

        if (! $this->shouldCreateProviderCustomer()) {
            return $result;
        }

        $provider = $this->findPaymentProvider();
        $firstConnection = ! $this->customer->paymentProviderCustomers()->exists();

        $providerCustomer = DB::transaction(function () use ($provider, $firstConnection): PaymentProviderCustomer {
            $created = $this->createProviderCustomer($provider);

            if (($this->params['code'] ?? null) !== null && $this->params['code'] !== '') {
                $created->code = $this->params['code'];
            }

            if ($firstConnection) {
                $created->is_default = true;
            }

            $created->save();

            $customer = $this->customer;

            if ($created->is_default && $customer->payment_provider === null) {
                $customer->payment_provider = $this->params['payment_provider'];
                $customer->payment_provider_code = $provider?->code;
                $customer->save();
            }

            return $created;
        });

        $result->payment_provider_customer = $providerCustomer->refresh();

        return $result;
    }

    /**
     * Rails: `create_provider_customer?` — only when the connection syncs
     * with the provider or carries an explicit provider_customer_id.
     */
    private function shouldCreateProviderCustomer(): bool
    {
        return (bool) ($this->params['sync_with_provider'] ?? false)
            || ! empty($this->params['provider_customer_id']);
    }

    private function findPaymentProvider(): ?\App\Models\PaymentProvider
    {
        $findResult = FindService::call(
            organizationId: $this->customer->organization_id,
            code: ($this->params['payment_provider_code'] ?? null) !== null && $this->params['payment_provider_code'] !== ''
                ? $this->params['payment_provider_code']
                : null,
            paymentProviderType: $this->params['payment_provider'],
        );

        return $findResult->success() ? $findResult->payment_provider : null;
    }

    /**
     * Rails: PaymentProviders::CreateCustomerFactory.new_instance(...).call! —
     * the per-provider AbstractCustomersCreateService create-or-update.
     */
    private function createProviderCustomer(?\App\Models\PaymentProvider $provider): PaymentProviderCustomer
    {
        $customer = $this->customer;

        $serviceClass = match ($this->params['payment_provider']) {
            'stripe' => \App\Services\PaymentProviders\Stripe\Customers\CreateOrUpdateService::class,
            'adyen' => \App\Services\PaymentProviders\Adyen\Customers\CreateService::class,
            'gocardless' => \App\Services\PaymentProviders\Gocardless\Customers\CreateService::class,
            'cashfree' => \App\Services\PaymentProviders\Cashfree\Customers\CreateService::class,
            'flutterwave' => \App\Services\PaymentProviders\Flutterwave\Customers\CreateService::class,
            'moneyhash' => \App\Services\PaymentProviders\Moneyhash\Customers\CreateService::class,
            default => null,
        };

        if ($serviceClass === null) {
            throw new LogicException("Payment provider '{$this->params['payment_provider']}' is not supported yet");
        }

        return $serviceClass::call(
            customer: $customer,
            paymentProviderId: $provider?->id,
            params: [
                'provider_customer_id' => $this->params['provider_customer_id'] ?? null,
                'provider_payment_methods' => $this->params['provider_payment_methods'] ?? null,
                'sync_with_provider' => $this->params['sync_with_provider'] ?? null,
            ],
        )->raiseIfError()->provider_customer;
    }
}
