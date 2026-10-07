<?php

declare(strict_types=1);

namespace App\Services\Payments;

use Throwable;
use App\Models\Invoice;
use App\Models\Payment;
use App\Enums\InvoiceStatus;
use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Jobs\PaymentReceipts\CreateJob;
use App\Services\Invoices\UpdateService;

/**
 * Port of Rails' Payments::ManualCreateService — POST /api/v1/payments
 * (recording a manual payment on an invoice).
 *
 * Preconditions in Rails order: invoice_id mandatory, amount_cents must be
 * a positive integer, invoice must exist (advance_charges invoices are
 * silently accepted-noop), license must be premium, invoice status must be
 * manually payable (finalized/open), paid_at format must be valid. Then the
 * payment row (status succeeded, payment_type manual) + invoice
 * total_paid_amount_cents / payment_status update in one transaction.
 *
 * TODO(port): the
 * Payment model validations Rails enforces on manual payments (credit
 * invoice must be fully covered, amount <= total due, no double success).
 */
class ManualCreateService extends BaseService
{
    public function __construct(
        private readonly Organization $organization,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('payment');

        $invoice = $this->checkPreconditions($result);

        if ($result->failure()) {
            return $result;
        }

        if ($invoice->typeEnum()?->label() === 'advance_charges') {
            $result->payment = null;

            return $result;
        }

        $amountCents = (int) $this->params['amount_cents'];

        $payment = new Payment([
            'organization_id' => $invoice->organization_id,
            'customer_id' => $invoice->customer_id,
            'invoice_id' => $invoice->id,
            'payable_type' => 'Invoice',
            'payable_id' => $invoice->id,
            'amount_cents' => $amountCents,
            'reference' => $this->params['reference'] ?? null,
            'amount_currency' => $invoice->currency,
            'status' => 'succeeded',
            'payable_payment_status' => 'succeeded',
            'payment_type' => 'manual',
            'created_at' => $this->parsedPaidAt(),
        ]);

        try {
            $payment->save();

            $totalPaidAmountCents = (int) Payment::query()
                ->where('payable_type', 'Invoice')
                ->where('payable_id', $invoice->id)
                ->where('payable_payment_status', 'succeeded')
                ->sum('amount_cents');

            $updateParams = ['total_paid_amount_cents' => $totalPaidAmountCents];

            if ($totalPaidAmountCents === (int) $invoice->total_amount_cents) {
                $updateParams['payment_status'] = 'succeeded';
            }

            UpdateService::callBang(
                invoice: $invoice,
                params: $updateParams,
                webhookNotification: true,
            );
        } catch (Throwable $e) {
            if ($e instanceof \App\Services\Failures\FailedResult) {
                return $this->embedFailure($result, $e);
            }

            throw $e;
        }

        $result->payment = $payment;

        // Rails: PaymentReceipts::CreateJob.perform_later(result.payment)
        // if organization.issue_receipts_enabled?.
        if ($this->organization->issueReceiptsEnabled()) {
            dispatch(new \App\Jobs\PaymentReceipts\CreateJob($payment));
        }

        // Rails after_commit: Integrations::Aggregator::Payments::CreateJob
        // .perform_later(payment: result.payment) if should_sync_payment?.
        if ($payment->shouldSyncPayment()) {
            dispatch(new \App\Jobs\Integrations\Aggregator\Payments\CreateJob($payment));
        }

        return $result;
    }

    /** Returns the invoice, or null after recording a precondition failure. */
    private function checkPreconditions(BaseResult $result): ?Invoice
    {
        $params = $this->params;

        if (($params['invoice_id'] ?? null) === null || $params['invoice_id'] === '') {
            $result->singleValidationFailure('value_is_mandatory', 'invoice_id');

            return null;
        }

        if (! isset($params['amount_cents']) || ! is_int($params['amount_cents']) || $params['amount_cents'] <= 0) {
            $result->singleValidationFailure('invalid_value', 'amount_cents');

            return null;
        }

        $invoice = $this->organization->invoices()
            ->where('id', $params['invoice_id'])
            ->first();

        if ($invoice === null) {
            $result->notFoundFailure('invoice');

            return null;
        }

        $status = $invoice->statusEnum();

        if (! $this->premium()) {
            $result->forbiddenFailure();

            return $invoice;
        }

        if ($status !== InvoiceStatus::Finalized && $status !== InvoiceStatus::Open) {
            $result->forbiddenFailure();

            return $invoice;
        }

        if (! $this->validPaidAt()) {
            $result->singleValidationFailure('invalid_date', 'paid_at');

            return $invoice;
        }

        return $invoice;
    }

    private function validPaidAt(): bool
    {
        $paidAt = $this->params['paid_at'] ?? null;

        if ($paidAt === null || $paidAt === '') {
            return true;
        }

        return strtotime((string) $paidAt) !== false;
    }

    private function parsedPaidAt(): ?\Carbon\CarbonInterface
    {
        $paidAt = $this->params['paid_at'] ?? null;

        if ($paidAt === null || $paidAt === '') {
            return null;
        }

        return \Illuminate\Support\Facades\Date::parse($paidAt, date_default_timezone_get())->utc();
    }
}
