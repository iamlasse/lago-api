<?php

declare(strict_types=1);

namespace App\Services\PaymentRequests;

use App\Jobs\SendWebhookJob;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\PaymentRequest;

use function in_array;

/**
 * Port of Rails' PaymentRequests::UpdateService — the internal payment
 * status transition arm the payments services call (NOT the API PATCH,
 * which Rails serves through the same service with more params).
 *
 * Only payment_status / ready_for_payment_processing are applied; an
 * invalid payment_status is a single validation failure; a changed
 * payment_status delivers the payment_request.payment_status_updated
 * webhook when asked.
 */
class UpdateService extends BaseService
{
    public function __construct(
        private readonly ?PaymentRequest $payable,
        private readonly array $params,
        private readonly bool $webhookNotification = false,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('payable');

        if ($this->payable === null) {
            return $result->notFoundFailure('payment_request');
        }

        if (array_key_exists('payment_status', $this->params)
            && ! self::validPaymentStatus($this->params['payment_status'])) {
            return $result->singleValidationFailure('value_is_invalid', 'payment_status');
        }

        $payable = $this->payable;

        if (array_key_exists('payment_status', $this->params)) {
            $payable->setPaymentStatus((string) $this->params['payment_status']);
        }

        if (array_key_exists('ready_for_payment_processing', $this->params)) {
            $payable->ready_for_payment_processing = (bool) $this->params['ready_for_payment_processing'];
        }

        $statusChanged = $payable->isDirty('payment_status');

        $payable->save();

        if ($statusChanged && $this->webhookNotification) {
            $this->deliverWebhook($payable);
        }

        $result->payable = $payable;

        return $result;
    }

    /** Rails: valid_payment_status? — PaymentRequest::PAYMENT_STATUS. */
    private static function validPaymentStatus(mixed $paymentStatus): bool
    {
        return in_array($paymentStatus, PaymentRequest::PAYMENT_STATUSES, true);
    }

    private function deliverWebhook(PaymentRequest $payable): void
    {
        SendWebhookJob::performLater('payment_request.payment_status_updated', $payable);
    }
}
