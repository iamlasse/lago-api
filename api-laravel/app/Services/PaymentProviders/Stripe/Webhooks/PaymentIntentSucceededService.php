<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Stripe\Webhooks;

use App\Services\BaseResult;
use App\Jobs\Payments\SetPaymentMethodAndCreateReceiptJob;

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

        $payment = $result->payment;

        if ($payment !== null) {
            // Rails: Payments::SetPaymentMethodAndCreateReceiptJob
            // .perform_later(payment:, provider_payment_method_id:) — the
            // payment method attach + the payment receipt creation.
            //
            // TODO(port): the provider_payment_method_data snapshot
            // (Payments::SetPaymentMethodDataService) rides in the same job.
            dispatch(new \App\Jobs\Payments\SetPaymentMethodAndCreateReceiptJob(payment: $payment, providerPaymentMethodId: $this->dataObject()['payment_method'] ?? null));
        }

        return $result;
    }
}
