<?php

declare(strict_types=1);

namespace App\Services\PaymentMethods;

use App\Models\Customer;
use App\Services\BaseResult;
use App\Models\PaymentMethod;
use App\Services\BaseService;
use App\Models\PaymentProviderCustomer;

/**
 * Port of Rails' PaymentMethods::FindOrCreateFromProviderService — finds
 * the customer's payment method for a provider method id (customer +
 * payment_provider_customer + provider_method_id) or builds it via
 * CreateFromProviderService; set_as_default flips the customer default.
 *
 * @see CreateFromProviderService
 */
class FindOrCreateFromProviderService extends BaseService
{
    public function __construct(
        private readonly ?Customer $customer,
        private readonly ?PaymentProviderCustomer $paymentProviderCustomer,
        private readonly ?string $providerMethodId,
        /** @var array<string, mixed> */
        private readonly array $params = [],
        private readonly bool $setAsDefault = false,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('payment_method');

        if ($this->providerMethodId === null) {
            return $result;
        }

        $paymentMethod = $this->findPaymentMethod() ?? $this->createFromProvider();

        if ($this->setAsDefault && $paymentMethod !== null) {
            SetAsDefaultService::call(paymentMethod: $paymentMethod)->raiseIfError();
        }

        $result->payment_method = $paymentMethod;

        return $result;
    }

    private function findPaymentMethod(): ?PaymentMethod
    {
        return PaymentMethod::query()
            ->where('customer_id', $this->customer?->id)
            ->where('payment_provider_customer_id', $this->paymentProviderCustomer?->id)
            ->where('provider_method_id', $this->providerMethodId)
            ->first();
    }

    /** Port of Rails' PaymentMethods::CreateFromProviderService. */
    private function createFromProvider(): ?PaymentMethod
    {
        if ($this->customer === null) {
            return null;
        }

        $methods = $this->params['provider_payment_methods'] ?? null;
        $providerMethodType = (is_array($methods) && $methods !== []) ? $methods[0] : 'card';

        $paymentMethod = new PaymentMethod([
            'organization_id' => $this->customer->organization_id,
            'customer_id' => $this->customer->id,
            'payment_provider_customer_id' => $this->paymentProviderCustomer?->id,
            'provider_method_type' => $providerMethodType,
            'provider_method_id' => $this->providerMethodId,
            'payment_provider_id' => $this->paymentProviderCustomer?->payment_provider_id,
            'details' => $this->params['details'] ?? null,
        ]);
        $paymentMethod->save();

        return $paymentMethod;
    }
}
