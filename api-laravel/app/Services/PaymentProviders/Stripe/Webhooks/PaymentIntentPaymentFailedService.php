<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Stripe\Webhooks;

use App\Services\BaseResult;

/**
 * Port of Rails' PaymentProviders::Stripe::Webhooks::PaymentIntentPaymentFailedService
 * — marks the payment failed (also used for "payment_intent.canceled").
 */
class PaymentIntentPaymentFailedService extends BaseService
{
    public function execute(): BaseResult
    {
        return $this->updatePaymentStatus('failed', static::makeResult('payment', 'invoice'));
    }
}
