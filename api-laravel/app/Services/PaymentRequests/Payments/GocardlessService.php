<?php

declare(strict_types=1);

namespace App\Services\PaymentRequests\Payments;

use App\Models\Payment;
use App\Services\BaseResult;

/**
 * Port of Rails' PaymentRequests::Payments::GocardlessService — the payment
 * request's GoCardless arm: `update_payment_status` only ("payments"
 * webhook events; GoCardless has no payment-request payment-link flow —
 * GeneratePaymentUrlService rejects the provider).
 */
class GocardlessService extends BaseService
{
    /** Rails: `update_payment_status`. */
    public static function updatePaymentStatus(string $providerPaymentId, string $status): BaseResult
    {
        $result = static::makeResult('payment', 'payable');

        $payment = Payment::query()->where('provider_payment_id', $providerPaymentId)->first();

        if ($payment === null) {
            return $result->notFoundFailure('gocardless_payment');
        }

        return static::updatePaymentAndPayable($result, $payment, $status);
    }
}
