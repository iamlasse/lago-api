<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Stripe\Webhooks;

use App\Services\BaseResult;

/**
 * Port of Rails' PaymentProviders::Stripe::Webhooks::PaymentIntentSucceededService
 * — settles the payment ("payment_intent.succeeded") and enqueues the
 * payment-method/receipt side effects.
 */
class PaymentIntentSucceededService extends BaseService
{
    public function execute(): BaseResult
    {
        $result = $this->updatePaymentStatus('succeeded', static::makeResult('payment', 'invoice'));

        // TODO(port): Payments::SetPaymentMethodAndCreateReceiptJob — sets
        // provider_payment_method_data from the Stripe PaymentMethod and
        // creates the payment receipt (payment receipts slice).

        return $result;
    }
}
