<?php

declare(strict_types=1);

namespace App\Services\PaymentRequests\Payments;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Values\StripePayment;
use App\Models\PaymentRequest;

use function array_key_exists;

/**
 * Port of Rails' PaymentRequests::Payments::StripeService — the
 * `update_payment_status` leg for a PaymentRequest payable (the
 * generate_payment_url checkout leg is TODO(port)).
 *
 * On a settled event: the payment row follows the raw provider status, the
 * payment request's payment_status is updated, and every applied invoice
 * that is not yet succeeded gets the same payment_status. The dunning
 * campaign reset and the requested-mailer leg are TODO(port) (dunning
 * campaigns are unported).
 */
class StripeService extends BaseService
{
    public function __construct(
        private readonly string $action,
        private readonly string $organizationId,
        private readonly string $status,
        private readonly StripePayment $stripePayment,
        private readonly ?int $amountCents = null,
    ) {
        parent::__construct();
    }

    /** Port of `update_payment_status`. */
    public static function updatePaymentStatus(
        string $organizationId,
        string $status,
        StripePayment $stripePayment,
        ?int $amountCents = null,
    ): BaseResult {
        return (new static(
            action: 'update_payment_status',
            organizationId: $organizationId,
            status: $status,
            stripePayment: $stripePayment,
            amountCents: $amountCents,
        ))->execute();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('payment', 'payable');

        $payment = Payment::query()->where('provider_payment_id', $this->stripePayment->id)->first();

        if ($payment !== null
            && $payment->payable !== null
            && $payment->payable->organization_id !== $this->organizationId) {
            return $result;
        }

        if ($payment === null) {
            $payment = $this->handleMissingPayment($result);
        }

        if ($payment === null) {
            return $result;
        }

        $payable = $payment->payable;

        if ($payable instanceof PaymentRequest && $payable->paymentSucceeded()) {
            $result->payment = $payment;
            $result->payable = $payable;

            return $result;
        }

        $processing = $this->status === 'processing';
        $payment->status = $this->status;

        $payablePaymentStatus = $payment->paymentProvider?->determinePaymentStatus($this->status);
        $payment->payable_payment_status = $payablePaymentStatus;
        $payment->save();

        $result->payment = $payment;
        $result->payable = $payable;

        if ($payable instanceof PaymentRequest) {
            $this->updatePayablePaymentStatus($payable, $payablePaymentStatus, $processing);
            $this->updateInvoicesPaymentStatus($payable, $payablePaymentStatus, $processing);
        }

        return $result;
    }

    /**
     * Rails: handle_missing_payment — recreate a payment row for a
     * one-time checkout settlement (metadata lago_payable_id) when the
     * payment request exists, is not succeeded, and is not already failed.
     */
    private function handleMissingPayment(BaseResult $result): ?Payment
    {
        $metadata = $this->stripePayment->metadata;

        if (! array_key_exists('lago_payable_id', $metadata)) {
            return null;
        }

        $payable = PaymentRequest::query()
            ->where('id', $metadata['lago_payable_id'])
            ->where('organization_id', $this->organizationId)
            ->first();

        if ($payable === null || $payable->paymentSucceeded() || $payable->paymentStatus() === 'failed') {
            return null;
        }

        $customer = $payable->customer;
        $provider = $customer->paymentProviderCustomers()->first()?->paymentProvider;

        $payment = new Payment([
            'organization_id' => $payable->organization_id,
            'payable_type' => 'PaymentRequest',
            'payable_id' => $payable->id,
            'customer_id' => $payable->customer_id,
            'payment_provider_id' => $provider?->id,
            'payment_provider_customer_id' => $customer->paymentProviderCustomers()->first()?->id,
            'amount_cents' => $this->amountCents ?? $payable->totalAmountCents(),
            'amount_currency' => $payable->amount_currency,
            'status' => 'pending',
        ]);

        $status = $provider?->determinePaymentStatus($this->stripePayment->status) ?? 'pending';
        $payment->provider_payment_id = $this->stripePayment->id;
        $payment->status = $this->stripePayment->status;
        $payment->payable_payment_status = $status === 'pending' ? 'processing' : $status;
        $payment->save();

        $result->payment = $payment;

        return $payment;
    }

    private function updatePayablePaymentStatus(PaymentRequest $payable, ?string $paymentStatus, bool $processing): void
    {
        $status = ($paymentStatus === 'processing') ? 'pending' : (string) $paymentStatus;

        $payable->payment_status = array_search($status, PaymentRequest::PAYMENT_STATUSES, true);
        $payable->ready_for_payment_processing = ! $processing && $status !== 'succeeded';
        $payable->save();

        // TODO(port): PaymentRequestMailer.requested when the payment request
        // is failed (mailer slice).
    }

    private function updateInvoicesPaymentStatus(PaymentRequest $payable, ?string $paymentStatus, bool $processing): void
    {
        $status = ($paymentStatus === 'processing') ? 'pending' : (string) $paymentStatus;

        foreach ($payable->invoices as $invoice) {
            if ($invoice->paymentSucceeded() && $status !== 'succeeded') {
                continue;
            }

            $params = [
                'payment_status' => $status,
                'ready_for_payment_processing' => ! $processing && $status !== 'succeeded',
            ];

            if ($status === 'succeeded') {
                $params['total_paid_amount_cents'] = (int) Payment::query()
                    ->where('payable_type', 'Invoice')
                    ->where('payable_id', $invoice->id)
                    ->where('payable_payment_status', 'succeeded')
                    ->sum('amount_cents');
            }

            \App\Services\Invoices\UpdateService::callBang(
                invoice: $invoice,
                params: $params,
                webhookNotification: true,
            );
        }
    }
}
