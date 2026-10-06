<?php

declare(strict_types=1);

namespace App\Services\CreditNotes\Refunds;

use Throwable;
use App\Models\Refund;
use App\Models\CreditNote;
use App\Services\BaseResult;
use App\Services\PaymentProviders\Adyen\Client;
use App\Services\PaymentProviders\Adyen\AdyenError;
use App\Services\PaymentProviders\Adyen\ValidationError;
use App\Services\PaymentProviders\Adyen\AuthenticationError;

/**
 * Port of Rails' CreditNotes::Refunds::AdyenService — the refund leg of a
 * refund-type credit note paid through Adyen.
 *
 * `create`: POST /v70/payments/{provider_payment_id}/refunds (paymentPspReference,
 * merchantAccount, amount{value,currency}) creates the pending Refund row
 * (provider_refund_id = the new pspReference) and the credit note's
 * refund_status follows. An AdyenError marks the refund failed, delivers
 * the error webhook and re-raises (Rails: `raise`).
 *
 * `update_status` (REFUND / REFUND_FAILED webhook events): the refund row
 * follows the provider status; a failed refund delivers the error webhook
 * and is a "refund_failed" service failure.
 */
class AdyenService extends BaseService
{
    /** Rails: `create`. */
    public static function create(CreditNote $creditNote): BaseResult
    {
        $result = static::makeResult('credit_note', 'refund');

        $result->credit_note = $creditNote;

        if (! static::shouldProcessRefund($creditNote)) {
            return $result;
        }

        $payment = static::payment($creditNote);
        $provider = $payment->paymentProvider;

        $client = new Client(
            apiKey: (string) ($provider?->apiKey() ?? ''),
            environment: $provider?->adyenStyleEnvironment() ?? 'test',
            livePrefix: (string) ($provider?->livePrefix() ?? ''),
        );

        try {
            [$status, $response] = $client->call(
                'post',
                'payments/'.$payment->provider_payment_id.'/refunds',
                self::refundParams($creditNote, $payment),
            );

            if (Client::responseFailed($status)) {
                throw Client::errorFromResponse($status, $response);
            }
        } catch (AuthenticationError|ValidationError $e) {
            static::markFailed($result, $creditNote, $payment, $e);

            throw $e;
        } catch (AdyenError $e) {
            static::markFailed($result, $creditNote, $payment, $e);

            // Rails: `raise` — the create leg re-raises Adyen errors.
            throw $e;
        }

        $refund = static::newRefund($creditNote, $payment, [
            'amount_cents' => (int) ($response['amount']['value'] ?? 0),
            'amount_currency' => (string) ($response['amount']['currency'] ?? ''),
            'status' => 'pending',
            'provider_refund_id' => (string) ($response['pspReference'] ?? ''),
        ]);
        $refund->save();

        static::updateCreditNoteStatus($creditNote, $refund->status);

        // TODO(port): Utils::SegmentTrack.refund_status_changed.

        $result->refund = $refund;

        return $result;
    }

    /**
     * Rails: `update_status` — the REFUND / REFUND_FAILED webhook legs.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function updateStatus(string $providerRefundId, string $status, array $metadata = []): BaseResult
    {
        $result = static::makeResult('credit_note', 'refund');

        try {
            $refund = Refund::query()->where('provider_refund_id', $providerRefundId)->first();

            if ($refund === null) {
                $missing = static::handleMissingRefund($result, $metadata, 'adyen_refund');

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

    /** Rails: adyen_refund_params. */
    private static function refundParams(CreditNote $creditNote, \App\Models\Payment $payment): array
    {
        return [
            'paymentPspReference' => $payment->provider_payment_id,
            'merchantAccount' => $payment->paymentProvider?->merchantAccount(),
            'amount' => [
                'value' => (int) $creditNote->refund_amount_cents,
                'currency' => mb_strtoupper((string) $creditNote->credit_amount_currency),
            ],
        ];
    }

    /**
     * Rails: create_adyen_refund's rescue — mark the refund failed, deliver
     * the error webhook, then the caller re-raises.
     */
    private static function markFailed(BaseResult $result, CreditNote $creditNote, \App\Models\Payment $payment, AdyenError $e): void
    {
        static::updateCreditNoteStatus($creditNote, 'failed');
        static::deliverErrorWebhook($creditNote, $payment, $e->msg, $e->code);
    }
}
