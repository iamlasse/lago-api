<?php

declare(strict_types=1);

namespace App\Services\Invoices\Payments;

use Throwable;
use LogicException;
use App\Models\Invoice;
use App\Models\Payment;
use App\Jobs\SendWebhookJob;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\Failures\FailedResult;
use App\Services\Invoices\UpdateService;

/**
 * Port of Rails' Invoices::Payments::GocardlessService — the webhook-driven
 * `update_payment_status` leg (GoCardless has no hosted-checkout
 * payment-url leg: Rails' GeneratePaymentUrlService rejects the provider
 * with "invalid_payment_provider").
 *
 * Semantics: the payment is found by provider_payment_id (missing ->
 * "gocardless_payment" not-found failure), an already-succeeded payable
 * short-circuits, payment.status gets the raw GoCardless action,
 * payable_payment_status the normalized one, and the invoice's
 * payment_status follows (Invoices::UpdateService).
 */
class GocardlessService extends BaseService
{
    public static function updatePaymentStatus(string $providerPaymentId, string $status): BaseResult
    {
        $result = static::makeResult('payment', 'invoice');

        $payment = Payment::query()->where('provider_payment_id', $providerPaymentId)->first();

        if ($payment === null) {
            // Rails: not_found_failure!(resource: "gocardless_payment").
            return $result->notFoundFailure('gocardless_payment');
        }

        try {
            $result->payment = $payment;
            $result->invoice = $invoice = $payment->payable;

            if (! $invoice instanceof Invoice || $invoice->paymentSucceeded()) {
                return $result;
            }

            $payment->status = $status;
            $payablePaymentStatus = $payment->paymentProvider?->determinePaymentStatus($payment->status);
            $payment->payable_payment_status = $payablePaymentStatus;
            $payment->save();

            if ($payablePaymentStatus === 'succeeded') {
                SendWebhookJob::performLater('payment.succeeded', $payment);
            }

            // Rails: Integrations::Aggregator::Payments::CreateJob
            // .perform_later(payment:) if payment.should_sync_payment?.
            if ($payment->shouldSyncPayment()) {
                \App\Jobs\Integrations\Aggregator\Payments\CreateJob::dispatch($payment);
            }

            self::updateInvoicePaymentStatus($invoice, (string) $payablePaymentStatus);

            return $result;
        } catch (FailedResult $e) {
            return $e->result;
        } catch (Throwable $e) {
            throw $e;
        }
    }

    /**
     * Rails: `update_payment_status` (no organization scoping — the lookup
     * is by provider_payment_id only).
     */
    /**
     * The Rails services dispatch actions by name (call!(:update_payment_status, ...));
     * the port exposes them as static entrypoints instead — this instance body is
     * never invoked.
     */
    public function execute(): BaseResult
    {
        throw new LogicException(static::class.' is dispatched through its static action entrypoints');
    }

    /** Rails: update_invoice_payment_status. */
    private static function updateInvoicePaymentStatus(Invoice $invoice, string $paymentStatus): void
    {
        $params = [
            'payment_status' => $paymentStatus,
            'ready_for_payment_processing' => $paymentStatus !== 'succeeded',
        ];

        if ($paymentStatus === 'succeeded') {
            $params['total_paid_amount_cents'] = (int) Payment::query()
                ->where('payable_type', 'Invoice')
                ->where('payable_id', $invoice->id)
                ->where('payable_payment_status', 'succeeded')
                ->sum('amount_cents');
        }

        UpdateService::callBang(
            invoice: $invoice,
            params: $params,
            webhookNotification: true,
        );
    }
}
