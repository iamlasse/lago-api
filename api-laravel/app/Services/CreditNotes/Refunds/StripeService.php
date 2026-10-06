<?php

declare(strict_types=1);

namespace App\Services\CreditNotes\Refunds;

use Throwable;
use App\Models\Refund;
use App\Models\CreditNote;
use App\Services\BaseResult;
use App\Services\PaymentProviders\Stripe\Client;
use App\Services\PaymentProviders\Stripe\StripeError;
use App\Services\PaymentProviders\Stripe\InvalidRequestError;

/**
 * Port of Rails' CreditNotes::Refunds::StripeService — the refund leg of a
 * refund-type credit note paid through Stripe.
 *
 * `create`: only a refundable, not-yet-succeeded credit note with a
 * refundable payment is processed; POST /v1/refunds (payment_intent,
 * amount, reason mapped from the credit note reason, metadata
 * lago_customer_id/lago_credit_note_id/lago_invoice_id, idempotency key
 * = credit note id) creates the pending Refund row and the credit note's
 * refund_status follows the refund's status. An InvalidRequestError marks
 * the refund failed and delivers the error webhook — the
 * charge_not_refundable code returns an empty result, anything else is a
 * "stripe_error" service failure.
 *
 * `update_status` (charge.refund.updated webhook): the refund row follows
 * the provider status; a failed refund delivers the error webhook and is a
 * "refund_failed" service failure.
 */
class StripeService extends BaseService
{
    /** Rails: INVALID_PAYMENT_METHOD_ERROR. */
    public const INVALID_PAYMENT_METHOD_ERROR = 'charge_not_refundable';

    /** Rails: `create` — refund the credit note through Stripe. */
    public static function create(CreditNote $creditNote): BaseResult
    {
        $result = static::makeResult('credit_note', 'refund');

        $result->credit_note = $creditNote;

        if (! static::shouldProcessRefund($creditNote)) {
            return $result;
        }

        $payment = static::payment($creditNote);
        $provider = static::paymentProviderFor($creditNote->customer);

        $client = new Client(
            apiKey: (string) ($provider?->secretKey() ?? ''),
            idempotencyKey: $creditNote->id,
        );

        try {
            $stripeRefund = $client->call('post', '/v1/refunds', self::refundPayload($creditNote, $payment));
        } catch (InvalidRequestError $e) {
            static::updateCreditNoteStatus($creditNote, 'failed');
            static::deliverErrorWebhook($creditNote, $payment, $e->getMessage(), $e->code());

            if ($e->code() === self::INVALID_PAYMENT_METHOD_ERROR) {
                return $result;
            }

            return $result->serviceFailure(code: 'stripe_error', message: $e->getMessage());
        } catch (StripeError $e) {
            static::updateCreditNoteStatus($creditNote, 'failed');
            static::deliverErrorWebhook($creditNote, $payment, $e->getMessage(), $e->code());

            return $result->serviceFailure(code: 'stripe_error', message: $e->getMessage());
        }

        $refund = static::newRefund($creditNote, $payment, [
            'amount_cents' => (int) ($stripeRefund['amount'] ?? 0),
            'amount_currency' => mb_strtoupper((string) ($stripeRefund['currency'] ?? '')),
            'status' => (string) ($stripeRefund['status'] ?? ''),
            'provider_refund_id' => (string) ($stripeRefund['id'] ?? ''),
        ]);
        $refund->save();

        static::updateCreditNoteStatus($creditNote, $refund->status);

        // TODO(port): Utils::SegmentTrack.refund_status_changed.

        $result->refund = $refund;

        return $result;
    }

    /**
     * Rails: `update_status` — the charge.refund.updated webhook leg.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function updateStatus(string $providerRefundId, string $status, array $metadata = []): BaseResult
    {
        $result = static::makeResult('credit_note', 'refund');

        try {
            $refund = Refund::query()->where('provider_refund_id', $providerRefundId)->first();

            if ($refund === null) {
                $missing = static::handleMissingRefund($result, $metadata, 'stripe_refund');

                return $missing ?? $result;
            }

            $result->refund = $refund;
            $creditNote = $result->credit_note = $refund->creditNote;

            if ($creditNote->refundSucceeded()) {
                return $result;
            }

            $refund->status = $status;
            $refund->save();

            static::updateCreditNoteStatus($creditNote, $status);

            // TODO(port): Utils::SegmentTrack.refund_status_changed.

            if ($status === 'failed') {
                static::deliverErrorWebhook($creditNote, $refund->payment, 'Payment refund failed', null);

                $result->serviceFailure(code: 'refund_failed', message: 'Refund failed to perform');
            }

            return $result;
        } catch (Throwable $e) {
            throw $e;
        }
    }

    /** Rails: stripe_refund_payload (+ stripe_reason). */
    private static function refundPayload(CreditNote $creditNote, \App\Models\Payment $payment): array
    {
        return [
            'payment_intent' => $payment->provider_payment_id,
            'amount' => (int) $creditNote->refund_amount_cents,
            'reason' => self::reason($creditNote),
            'metadata' => [
                'lago_customer_id' => $creditNote->customer_id,
                'lago_credit_note_id' => $creditNote->id,
                'lago_invoice_id' => $creditNote->invoice_id,
            ],
        ];
    }

    /** Rails: stripe_reason — the credit note reason mapped onto Stripe's. */
    private static function reason(CreditNote $creditNote): ?string
    {
        return match ($creditNote->reasonEnum()?->value ?? $creditNote->reason) {
            'duplicated_charge' => 'duplicate',
            'product_unsatisfactory', 'order_change', 'order_cancellation' => 'requested_by_customer',
            'fraudulent_charge' => 'fraudulent',
            default => null,
        };
    }
}
