<?php

declare(strict_types=1);

namespace App\Services\CreditNotes\Refunds;

use Throwable;
use App\Models\Refund;
use App\Models\CreditNote;
use App\Services\BaseResult;
use App\Services\PaymentProviders\Gocardless\Client;
use App\Services\PaymentProviders\Gocardless\GoCardlessError;

/**
 * Port of Rails' CreditNotes::Refunds::GocardlessService — the refund leg
 * of a refund-type credit note paid through GoCardless.
 *
 * `create`: POST /refunds (amount, total_amount_confirmation,
 * links[payment], metadata lago_credit_note_id/lago_invoice_id/reason,
 * Idempotency-Key = credit note id — the API takes at most 3 metadata
 * keys) creates the Refund row with the provider's raw status and the
 * credit note's refund_status follows the mapped status. A GoCardless
 * error marks the refund failed, delivers the error webhook and re-raises;
 * a validation error does the same but returns an empty result.
 *
 * `update_status` (refunds webhook events): the refund row follows the
 * provider status (mapped); a failed status delivers the error webhook and
 * is a "refund_failed" service failure.
 */
class GocardlessService extends BaseService
{
    /** Rails: PENDING_STATUSES. */
    public const PENDING_STATUSES = ['created', 'pending_submission', 'submitted', 'refund_settled'];

    /** Rails: SUCCESS_STATUSES. */
    public const SUCCESS_STATUSES = ['paid'];

    /** Rails: FAILED_STATUSES. */
    public const FAILED_STATUSES = ['cancelled', 'bounced', 'funds_returned', 'failed'];

    /** Rails: `create`. */
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
            accessToken: (string) ($provider?->accessToken() ?? ''),
            environment: $provider?->gocardlessEnvironment() ?? 'live',
        );

        try {
            [$status, $response] = $client->call(
                'post',
                '/refunds',
                self::refundParams($creditNote, $payment),
                ['Idempotency-Key' => $creditNote->id],
            );

            if ($status >= 400) {
                $error = $response['error'] ?? $response;

                throw new GoCardlessError(
                    self::firstErrorMessage($response),
                    (string) ($error['error_type'] ?? $error['type'] ?? 'gocardless_error'),
                );
            }

            $gocardlessRefund = $response['refunds'] ?? $response;
        } catch (GoCardlessError $e) {
            static::updateCreditNoteStatus($creditNote, 'failed');
            static::deliverErrorWebhook($creditNote, $payment, $e->getMessage(), $e->code);

            // Rails: a ValidationError returns an empty result, any other
            // GoCardlessPro::Error re-raises.
            if ($e->code === 'validation_error') {
                return $result;
            }

            throw $e;
        }

        $refund = static::newRefund($creditNote, $payment, [
            'amount_cents' => (int) ($gocardlessRefund['amount'] ?? 0),
            'amount_currency' => mb_strtoupper((string) ($gocardlessRefund['currency'] ?? '')),
            'status' => (string) ($gocardlessRefund['status'] ?? ''),
            'provider_refund_id' => (string) ($gocardlessRefund['id'] ?? ''),
        ]);
        $refund->save();

        static::updateCreditNoteStatus($creditNote, self::creditNoteStatus($refund->status));

        // TODO(port): Utils::SegmentTrack.refund_status_changed.

        $result->refund = $refund;

        return $result;
    }

    /**
     * Rails: `update_status` — the refunds webhook events leg.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function updateStatus(string $providerRefundId, string $status, array $metadata = []): BaseResult
    {
        $result = static::makeResult('credit_note', 'refund');

        try {
            $refund = Refund::query()->where('provider_refund_id', $providerRefundId)->first();

            if ($refund === null) {
                $missing = static::handleMissingRefund($result, $metadata, 'gocardless_refund');

                return $missing ?? $result;
            }

            $result->refund = $refund;
            $creditNote = $result->credit_note = $refund->creditNote;

            if ($creditNote->refundSucceeded()) {
                return $result;
            }

            $refund->status = $status;
            $refund->save();

            static::updateCreditNoteStatus($creditNote, self::creditNoteStatus($refund->status));

            // TODO(port): Utils::SegmentTrack.refund_status_changed.

            if (in_array($status, self::FAILED_STATUSES, true)) {
                static::deliverErrorWebhook($creditNote, $refund->payment, 'Payment refund failed', null);

                $result->serviceFailure(code: 'refund_failed', message: 'Refund failed to perform');
            }

            return $result;
        } catch (Throwable $e) {
            throw $e;
        }
    }

    /** Rails: credit_note_status — the provider status mapped onto the enum. */
    public static function creditNoteStatus(string $status): string
    {
        if (in_array($status, self::PENDING_STATUSES, true)) {
            return 'pending';
        }

        if (in_array($status, self::SUCCESS_STATUSES, true)) {
            return 'succeeded';
        }

        if (in_array($status, self::FAILED_STATUSES, true)) {
            return 'failed';
        }

        return $status;
    }

    /**
     * Rails: create_gocardless_refund — the API accepts at most 3 metadata
     * keys (developer.gocardless.com#refunds-create-a-refund).
     */
    private static function refundParams(CreditNote $creditNote, \App\Models\Payment $payment): array
    {
        return [
            'amount' => (int) $creditNote->refund_amount_cents,
            'total_amount_confirmation' => (int) $creditNote->refund_amount_cents,
            'links' => ['payment' => $payment->provider_payment_id],
            'metadata' => [
                'lago_credit_note_id' => $creditNote->id,
                'lago_invoice_id' => $creditNote->invoice_id,
                'reason' => (string) ($creditNote->reasonEnum() !== null
                    ? (\App\Enums\CreditNoteReason::options()[$creditNote->reasonEnum()->value] ?? '')
                    : ''),
            ],
        ];
    }

    /** @param array<string, mixed> $response */
    private static function firstErrorMessage(array $response): string
    {
        $error = $response['error'] ?? $response;

        if (is_array($error['message'] ?? null)) {
            $first = reset($error['message']);

            return is_array($first) ? (string) reset($first) : (string) $first;
        }

        return (string) ($error['message'] ?? 'GoCardless request failed');
    }
}
