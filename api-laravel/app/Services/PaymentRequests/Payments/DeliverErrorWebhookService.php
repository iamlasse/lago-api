<?php

declare(strict_types=1);

namespace App\Services\PaymentRequests\Payments;

use App\Jobs\SendWebhookJob;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\PaymentRequest;

/**
 * Port of Rails' PaymentRequests::Payments::DeliverErrorWebhookService —
 * enqueues the payment_request.payment_failure webhook with the provider
 * error details.
 *
 * TODO(port): the webhook builder registration (Webhooks::PaymentProviders::
 * PaymentRequestPaymentFailureService), like the other provider-error
 * events.
 */
class DeliverErrorWebhookService extends BaseService
{
    /**
     * Rails: call_async.
     *
     * @param  array<string, mixed>  $params
     */
    public static function callAsync(PaymentRequest $paymentRequest, array $params): BaseResult
    {
        $result = static::makeResult();

        SendWebhookJob::performLater('payment_request.payment_failure', $paymentRequest, $params);

        return $result;
    }
}
