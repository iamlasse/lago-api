<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Gocardless\Webhooks;

use App\Services\BaseResult;
use App\Models\PaymentMethod;
use App\Services\BaseService;
use App\Models\PaymentProvider;
use App\Services\PaymentMethods\DestroyService;

/**
 * Port of Rails' Gocardless::Webhooks::MandateCancelledService — finds the
 * payment method by the cancelled mandate id, clears the provider
 * customer's stored provider_mandate_id when it points at it, and destroys
 * the payment method. A missing method is a silent return.
 */
class MandateCancelledService extends BaseService
{
    public function __construct(
        private readonly PaymentProvider $paymentProvider,
        private readonly string $mandateId,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('gocardless_customer', 'payment_method');

        $paymentMethod = PaymentMethod::query()
            ->where('organization_id', $this->paymentProvider->organization_id)
            ->where('payment_provider_id', $this->paymentProvider->id)
            ->where('provider_method_id', $this->mandateId)
            ->first();

        if ($paymentMethod === null) {
            return $result;
        }

        $gocardlessCustomer = $paymentMethod->paymentProviderCustomer;
        $result->gocardless_customer = $gocardlessCustomer;

        if ($gocardlessCustomer !== null
            && $gocardlessCustomer->getFromSettings('provider_mandate_id') === $this->mandateId) {
            $gocardlessCustomer->pushToSettings('provider_mandate_id', null);
            $gocardlessCustomer->save();
        }

        $result->payment_method = DestroyService::call(paymentMethod: $paymentMethod)->payment_method;

        return $result;
    }
}
