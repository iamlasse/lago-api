<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Adyen;

use Throwable;
use LogicException;
use App\Models\Payment;
use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\Failures\FailedResult;
use App\Services\Invoices\Payments\AdyenService;
use App\Services\PaymentProviderCustomers\AdyenService as AdyenCustomerService;

/**
 * Port of Rails' PaymentProviders::Adyen::HandleEventService — the
 * per-eventCode dispatcher over the (verified) NotificationRequestItem:
 *  - AUTHORISATION moves the one-time payment status, or — for a zero-amount
 *    authorisation — pre-authorises the customer's stored card;
 *  - CANCELLATION moves the payment back to "Cancelled" (originalReference
 *    points at the cancelled payment; pspReference is the cancel
 *    modification's own id);
 *  - REFUND / REFUND_FAILED move the credit note refund status
 *    (CreditNotes::Refunds::AdyenService#update_status);
 *  - CHARGEBACK dispatches the dispute-lost flow;
 *  - ignored event codes return silently, unknown ones service-fail.
 *
 * Note the additionalData keys are LITERAL dotted strings
 * ("metadata.lago_invoice_id") — not nested hashes.
 */
class HandleEventService extends BaseService
{
    public function __construct(
        private readonly Organization $organization,
        private readonly string $eventJson,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult();

        try {
            $event = json_decode($this->eventJson, true, 512, JSON_THROW_ON_ERROR);

            $eventCode = (string) ($event['eventCode'] ?? '');

            if (in_array($eventCode, \App\Models\PaymentProvider::ADYEN_IGNORED_WEBHOOK_EVENTS, true)) {
                return $result;
            }

            if (! in_array($eventCode, \App\Models\PaymentProvider::ADYEN_WEBHOOKS_EVENTS, true)) {
                return $result->serviceFailure(
                    code: 'webhook_error',
                    message: "Invalid adyen event code: {$eventCode}",
                );
            }

            return match ($eventCode) {
                'AUTHORISATION' => $this->handleAuthorisation($event),
                'CANCELLATION' => $this->handleCancellation($event),
                'REFUND' => $this->handleRefund($event),
                'CHARGEBACK' => $this->handleChargeback($event),
                'REFUND_FAILED' => $this->handleRefundFailed($event),
                default => $result,
            };
        } catch (FailedResult $e) {
            return $e->result;
        } catch (Throwable $e) {
            throw $e;
        }
    }

    /**
     * Rails: PAYMENT_SERVICE_CLASS_MAP — the payable's service class; an
     * unknown type is Rails' NameError.
     */
    private static function paymentServiceClass(?string $payableType): string
    {
        return match ($payableType ?? 'Invoice') {
            'Invoice' => AdyenService::class,
            'PaymentRequest' => \App\Services\PaymentRequests\Payments\AdyenService::class,
            default => throw new LogicException("Invalid lago_payable_type: {$payableType}"),
        };
    }

    /** Rails: the AUTHORISATION arm. */
    private function handleAuthorisation(array $event): BaseResult
    {
        $result = static::makeResult();

        $amount = $event['amount']['value'] ?? null;
        $paymentType = $event['additionalData']['metadata.payment_type'] ?? null;

        if ($paymentType === 'one-time') {
            return $this->updatePaymentStatus($event, $paymentType);
        }

        if ($amount !== 0) {
            return $result;
        }

        AdyenCustomerService::preauthorise(
            organization: $this->organization,
            event: $event,
        )->raiseIfError();

        return $result;
    }

    /** Rails: update_payment_status(payment_type) — the one-time payment. */
    private function updatePaymentStatus(array $event, string $paymentType): BaseResult
    {
        $result = static::makeResult();

        $success = ($event['success'] ?? null) === 'true';

        $metadata = [
            'payment_type' => $paymentType,
            'lago_invoice_id' => $event['additionalData']['metadata.lago_invoice_id'] ?? null,
            'lago_payable_id' => $event['additionalData']['metadata.lago_payable_id'] ?? null,
            'lago_payable_type' => $event['additionalData']['metadata.lago_payable_type'] ?? null,
        ];

        $serviceClass = self::paymentServiceClass($metadata['lago_payable_type']);

        // NOTE: only the invoice service scopes its payable lookup by
        // organization, the payment request one still resolves its payable
        // without it.
        if ($serviceClass === AdyenService::class) {
            $serviceClass::updatePaymentStatus(
                organizationId: $this->organization->id,
                providerPaymentId: (string) ($event['pspReference'] ?? ''),
                status: $success ? 'succeeded' : 'failed',
                amountCents: isset($event['amount']['value']) ? (int) $event['amount']['value'] : null,
                metadata: $metadata,
            )->raiseIfError();
        } else {
            $serviceClass::updatePaymentStatus(
                providerPaymentId: (string) ($event['pspReference'] ?? ''),
                status: $success ? 'succeeded' : 'failed',
                amountCents: isset($event['amount']['value']) ? (int) $event['amount']['value'] : null,
                metadata: $metadata,
            )->raiseIfError();
        }

        return $result;
    }

    /** Rails: the CANCELLATION arm. */
    private function handleCancellation(array $event): BaseResult
    {
        $result = static::makeResult();

        if (($event['success'] ?? null) !== 'true') {
            return $result;
        }

        $providerPaymentId = $event['originalReference'] ?? null;

        if ($providerPaymentId === null || $providerPaymentId === '') {
            return $result;
        }

        $payment = Payment::query()->where('provider_payment_id', $providerPaymentId)->first();

        if ($payment === null) {
            return $result;
        }

        // Rails resolves the payable's service class from
        // payment.payable_type; only the invoice service scopes its payable
        // lookup by organization.
        $serviceClass = self::paymentServiceClass($payment->payable_type);

        if ($serviceClass === AdyenService::class) {
            $serviceClass::updatePaymentStatus(
                organizationId: $this->organization->id,
                providerPaymentId: $providerPaymentId,
                status: 'Cancelled',
                metadata: ['lago_payable_type' => $payment->payable_type],
            )->raiseIfError();
        } else {
            $serviceClass::updatePaymentStatus(
                providerPaymentId: $providerPaymentId,
                status: 'Cancelled',
                metadata: ['lago_payable_type' => $payment->payable_type],
            )->raiseIfError();
        }

        return $result;
    }

    /** Rails: the REFUND arm — CreditNotes::Refunds::AdyenService.update_status. */
    private function handleRefund(array $event): BaseResult
    {
        $result = static::makeResult();

        $status = (($event['success'] ?? null) === 'true') ? 'succeeded' : 'failed';

        \App\Services\CreditNotes\Refunds\AdyenService::updateStatus(
            providerRefundId: (string) ($event['pspReference'] ?? ''),
            status: $status,
        )->raiseIfError();

        return $result;
    }

    /** Rails: the REFUND_FAILED arm. */
    private function handleRefundFailed(array $event): BaseResult
    {
        $result = static::makeResult();

        if (($event['success'] ?? null) !== 'true') {
            return $result;
        }

        \App\Services\CreditNotes\Refunds\AdyenService::updateStatus(
            providerRefundId: (string) ($event['pspReference'] ?? ''),
            status: 'failed',
        )->raiseIfError();

        return $result;
    }

    /** Rails: the CHARGEBACK arm. */
    private function handleChargeback(array $event): BaseResult
    {
        Webhooks\ChargebackService::call(
            organizationId: $this->organization->id,
            eventJson: $this->eventJson,
        );

        return static::makeResult();
    }
}
