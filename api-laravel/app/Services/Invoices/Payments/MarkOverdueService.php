<?php

declare(strict_types=1);

namespace App\Services\Invoices\Payments;

use App\Models\Invoice;
use App\Jobs\SendWebhookJob;
use App\Services\BaseResult;
use App\Services\BaseService;

/**
 * Port of Rails' Invoices::Payments::MarkOverdueService
 * (app/services/invoices/payments/mark_overdue_service.rb) — flags a
 * finalized, unpaid, past-due invoice as payment_overdue and emits the
 * `invoice.payment_overdue` webhook.
 */
class MarkOverdueService extends BaseService
{
    public function __construct(private readonly ?Invoice $invoice) {}

    public function execute(): BaseResult
    {
        $result = static::makeResult('invoice');

        if ($this->invoice === null) {
            return $result->notFoundFailure('invoice');
        }

        if (! $this->invoice->isFinalized()) {
            return $result->notAllowedFailure('invoice_not_finalized');
        }

        if ($this->invoice->paymentSucceeded()) {
            return $result->notAllowedFailure('invoice_payment_already_succeeded');
        }

        if ($this->invoice->payment_due_date->gt(now())) {
            return $result->notAllowedFailure('invoice_due_date_in_future');
        }

        if ($this->invoice->payment_dispute_lost_at !== null) {
            return $result->notAllowedFailure('invoice_dispute_lost');
        }

        $this->invoice->payment_overdue = true;
        $this->invoice->save();

        $result->invoice = $this->invoice;

        SendWebhookJob::performLater('invoice.payment_overdue', $this->invoice);

        return $result;
    }
}
