<?php

declare(strict_types=1);

namespace App\Services\Invoices\Payments;

use App\Models\Invoice;
use App\Jobs\SendWebhookJob;
use App\Services\BaseResult;
use App\Services\BaseService;

/**
 * Port of Rails' Invoices::Payments::RetryService
 * (app/services/invoices/payments/retry_service.rb) — "Retry invoice
 * payment": guards the invoice status (draft/voided/payment succeeded ->
 * invalid_status; not ready for payment processing ->
 * payment_processor_is_currently_handling_payment), then re-runs the payment
 * creation asynchronously. Without a payment provider the
 * invoice.payment_failure webhook announces the missing provider.
 */
class RetryService extends BaseService
{
    public function __construct(
        private readonly ?Invoice $invoice,
        private readonly array $paymentMethodParams = [],
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('invoice');

        $invoice = $this->invoice;

        if ($invoice === null) {
            return $result->notFoundFailure('invoice');
        }

        if ($invoice->isDraft() || $invoice->isVoided() || $invoice->paymentSucceeded()) {
            return $result->notAllowedFailure('invalid_status');
        }

        if (! (bool) $invoice->ready_for_payment_processing) {
            return $result->notAllowedFailure('payment_processor_is_currently_handling_payment');
        }

        $createResult = (new CreateService(
            invoice: $invoice,
            paymentMethodParams: $this->paymentMethodParams,
        ))->callAsync();

        if ($createResult->payment_provider === null) {
            $this->deliverWebhook($invoice);
        }

        $result->invoice = $invoice;

        return $result;
    }

    private function deliverWebhook(Invoice $invoice): void
    {
        SendWebhookJob::performLater('invoice.payment_failure', $invoice, [
            'error_details' => ['code' => 'customer_must_have_payment_provider'],
        ]);
    }
}
