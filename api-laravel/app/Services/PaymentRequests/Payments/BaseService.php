<?php

declare(strict_types=1);

namespace App\Services\PaymentRequests\Payments;

use LogicException;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Customer;
use App\Services\BaseService;
use App\Models\PaymentRequest;
use App\Services\PaymentProviders\FindService;
use App\Services\PaymentRequests\UpdateService;
use App\Services\Invoices\UpdateService as InvoiceUpdateService;

/**
 * Shared legs of Rails' PaymentRequests::Payments::* services (each Rails
 * copy is verbatim-duplicated; the port factors the common helpers here and
 * keeps the provider-specific legs in the subclasses):
 *  - `update_payable_payment_status` / `update_invoices_payment_status` —
 *    the payment request and each applied invoice follow the payment's
 *    normalized status (a "processing" provider status is not a payment
 *    request state, so the ready flag stays false only for succeeded);
 *  - `update_invoices_paid_amount_cents` (the Updatable concern) — on
 *    success each invoice's total_paid_amount_cents absorbs its remaining
 *    due amount;
 *  - `reset_customer_dunning_campaign_status` (TODO(port) — dunning
 *    campaigns reset leg) and SegmentTrack/mailer legs are deferred.
 */
abstract class BaseService extends BaseService
{
    public function execute(): BaseResult
    {
        throw new LogicException(static::class.' is dispatched through its static entrypoints');
    }

    /** Rails: update_payable_payment_status. */
    protected static function updatePayablePaymentStatus(
        PaymentRequest $payable,
        ?string $paymentStatus,
        bool $deliverWebhook = true,
        bool $processing = false,
    ): void {
        UpdateService::call(
            payable: $payable,
            params: [
                'payment_status' => $paymentStatus === 'processing' ? 'pending' : (string) $paymentStatus,
                // NOTE: A proper `processing` payment status should be introduced for payment_requests.
                'ready_for_payment_processing' => ! $processing && ! self::paymentStatusSucceeded($paymentStatus),
            ],
            webhookNotification: $deliverWebhook,
        )->raiseIfError();
    }

    /** Rails: update_invoices_payment_status. */
    protected static function updateInvoicesPaymentStatus(
        PaymentRequest $payable,
        ?string $paymentStatus,
        bool $deliverWebhook = true,
        bool $processing = false,
    ): void {
        $status = $paymentStatus === 'processing' ? 'pending' : (string) $paymentStatus;

        foreach ($payable->invoices as $invoice) {
            if ($invoice->paymentSucceeded() && ! self::paymentStatusSucceeded($paymentStatus)) {
                continue;
            }

            InvoiceUpdateService::callBang(
                invoice: $invoice,
                params: [
                    'payment_status' => $status,
                    // NOTE: A proper `processing` payment status should be introduced for invoices.
                    'ready_for_payment_processing' => ! $processing && ! self::paymentStatusSucceeded($paymentStatus),
                ],
                webhookNotification: $deliverWebhook,
            );
        }
    }

    /** Rails: update_invoices_paid_amount_cents (PaymentRequests::Payments::Updatable). */
    protected static function updateInvoicesPaidAmountCents(?PaymentRequest $payable, ?string $paymentStatus): void
    {
        if ($payable === null || ! self::paymentStatusSucceeded($paymentStatus)) {
            return;
        }

        foreach ($payable->invoices as $invoice) {
            InvoiceUpdateService::callBang(invoice: $invoice, params: [
                'total_paid_amount_cents' => self::totalPaidAmountCents($invoice),
            ]);
        }
    }

    /** Rails: total_paid_amount_cents(invoice) — paid + still due. */
    protected static function totalPaidAmountCents(Invoice $invoice): int
    {
        return (int) $invoice->total_paid_amount_cents + (int) $invoice->totalDueAmountCents();
    }

    /** Rails: payment_status_succeeded?. */
    protected static function paymentStatusSucceeded(?string $paymentStatus): bool
    {
        return $paymentStatus === 'succeeded';
    }

    /**
     * The common body of the providers' `update_payment_status` — the
     * payment row follows the raw provider status, its
     * payable_payment_status the normalized one, and the payment request +
     * applied invoices follow.
     *
     * @param  \App\Services\BaseResult  $result  shaped (payment, payable)
     */
    protected static function updatePaymentAndPayable(BaseResult $result, Payment $payment, string $status): BaseResult
    {
        $payable = $payment->payable;

        $result->payment = $payment;
        $result->payable = $payable;

        if ($payable->paymentSucceeded()) {
            return $result;
        }

        $payment->status = $status;

        $payablePaymentStatus = $payment->paymentProvider?->determinePaymentStatus($payment->status);
        $payment->payable_payment_status = $payablePaymentStatus;
        $payment->save();

        static::updatePayablePaymentStatus($payable, $payablePaymentStatus);
        static::updateInvoicesPaymentStatus($payable, $payablePaymentStatus);
        static::updateInvoicesPaidAmountCents($payable, $payablePaymentStatus);
        static::resetCustomerDunningCampaignStatus($payable, $payablePaymentStatus);

        static::deliverRequestedMailerIfFailed($payable);

        return $result;
    }

    /** Rails: Customers::PaymentProviderFinder#payment_provider. */
    protected static function paymentProviderFor(?Customer $customer): ?\App\Models\PaymentProvider
    {
        if ($customer?->payment_provider === null) {
            return null;
        }

        $findResult = FindService::call(
            organizationId: $customer->organization_id,
            code: $customer->payment_provider_code,
            paymentProviderType: $customer->payment_provider,
        );

        if ($findResult->failure()) {
            return null;
        }

        return $findResult->payment_provider;
    }

    /**
     * Rails: reset_customer_dunning_campaign_status — on success the
     * customer's dunning campaign for the payable currency is reset.
     * TODO(port) with the dunning-campaigns reset slice.
     */
    protected static function resetCustomerDunningCampaignStatus(PaymentRequest $payable, ?string $paymentStatus): void
    {
        // TODO(port): customer.reset_dunning_campaign_for_currency!(payable.currency).
    }

    /**
     * Rails: PaymentRequestMailer.requested when the payment request failed.
     * TODO(port) with the mailer slice.
     */
    protected static function deliverRequestedMailerIfFailed(PaymentRequest $payable): void
    {
        // TODO(port): PaymentRequestMailer.with(payment_request:).requested.deliver_later.
    }
}
