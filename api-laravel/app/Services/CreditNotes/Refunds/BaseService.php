<?php

declare(strict_types=1);

namespace App\Services\CreditNotes\Refunds;

use LogicException;
use App\Models\Refund;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Customer;
use App\Models\CreditNote;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Enums\CreditNoteRefundStatus;
use App\Services\PaymentProviders\FindService;

/**
 * Shared legs of Rails' CreditNotes::Refunds::{Stripe,Adyen,Gocardless}
 * services (each Rails copy is verbatim-duplicated; the port factors the
 * common helpers here and keeps the provider-specific legs in the
 * subclasses):
 *  - `should_process_refund?` — only a refund-type, not-yet-succeeded
 *    credit note on an invoice without a lost dispute and with a
 *    refundable payment is processed;
 *  - `payment` — the invoice's `refundable_payment` (the invoice's own
 *    succeeded payment, else a succeeded payment request's);
 *  - `update_credit_note_status` — the credit note's refund_status follows
 *    the refund and refunded_at is stamped on success;
 *  - `deliver_error_webhook` — the credit_note.provider_refund_failure
 *    webhook;
 *  - `handle_missing_refund` — refunds not initiated by Lago (no
 *    lago_invoice_id metadata, or an invoice id unknown to this instance)
 *    return silently; a known invoice id with no refund row fails with
 *    not_found.
 *
 * TODO(port): Utils::SegmentTrack.refund_status_changed (segment slice) and
 * Utils::ActivityLog.produce("credit_note.refund_failure") (activity log
 * slice).
 */
abstract class BaseService extends BaseService
{
    public function execute(): BaseResult
    {
        throw new LogicException(static::class.' is dispatched through its static entrypoints');
    }

    /** Rails: should_process_refund?. */
    protected static function shouldProcessRefund(CreditNote $creditNote): bool
    {
        if (! $creditNote->refunded()
            || $creditNote->refundSucceeded()
            || $creditNote->invoice->payment_dispute_lost_at !== null) {
            return false;
        }

        return self::payment($creditNote) !== null;
    }

    /** Rails: payment — the invoice's refundable payment. */
    protected static function payment(CreditNote $creditNote): ?Payment
    {
        return $creditNote->invoice->refundablePayment();
    }

    /** Rails: update_credit_note_status. */
    protected static function updateCreditNoteStatus(CreditNote $creditNote, string $status): void
    {
        $creditNote->refund_status = CreditNoteRefundStatus::from(
            array_search($status, CreditNoteRefundStatus::options(), true),
        );

        if ($creditNote->refundSucceeded()) {
            $creditNote->refunded_at = now();
        }

        $creditNote->save();
    }

    /**
     * Rails: deliver_error_webhook — the provider-refund-failure webhook
     * (the webhook builder registration is a later slice, like the other
     * provider-error events).
     */
    protected static function deliverErrorWebhook(
        CreditNote $creditNote,
        ?Payment $payment,
        string $message,
        ?string $code,
    ): void {
        \App\Jobs\SendWebhookJob::performLater(
            'credit_note.provider_refund_failure',
            $creditNote,
            [
                'provider_customer_id' => self::paymentProviderCustomer($payment)?->provider_customer_id,
                'provider_error' => [
                    'message' => $message,
                    'error_code' => $code,
                ],
            ],
        );
    }

    /**
     * Rails: handle_missing_refund — a refund Lago did not initiate (no
     * lago_invoice_id in the metadata) or whose invoice is unknown to this
     * instance returns null (silent); a known invoice id with no matching
     * refund fails with not_found.
     *
     * @param  array<string, mixed>  $metadata
     */
    protected static function handleMissingRefund(BaseResult $result, array $metadata, string $resource): ?BaseResult
    {
        if (! array_key_exists('lago_invoice_id', $metadata) || $metadata['lago_invoice_id'] === null) {
            return null;
        }

        if (Invoice::query()->find($metadata['lago_invoice_id']) === null) {
            return null;
        }

        return $result->notFoundFailure($resource);
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

    /** Rails: Customers::PaymentProviderFinder#payment_provider_customer (with_discarded). */
    protected static function paymentProviderCustomer(?Payment $payment): ?\App\Models\PaymentProviderCustomer
    {
        if ($payment === null) {
            return null;
        }

        return \App\Models\PaymentProviderCustomer::withTrashed()
            ->find($payment->payment_provider_customer_id);
    }

    /**
     * Rails: the Refund.new(...) column set shared by all three providers —
     * amount/status/provider_refund_id differ per provider and are filled by
     * the caller.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected static function newRefund(CreditNote $creditNote, Payment $payment, array $overrides): Refund
    {
        return new Refund(array_merge([
            'organization_id' => $creditNote->organization_id,
            'credit_note_id' => $creditNote->id,
            'refundable_type' => $creditNote->railsName(),
            'refundable_id' => $creditNote->id,
            'reason' => 'credit_note',
            'payment_id' => $payment->id,
            'payment_provider_id' => $payment->payment_provider_id,
            'payment_provider_customer_id' => $payment->payment_provider_customer_id,
        ], $overrides));
    }
}
