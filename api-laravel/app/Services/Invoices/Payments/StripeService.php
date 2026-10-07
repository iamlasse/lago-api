<?php

declare(strict_types=1);

namespace App\Services\Invoices\Payments;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Customer;
use App\Jobs\SendWebhookJob;
use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Values\StripePayment;
use App\Enums\InvoicePaymentStatus;
use App\Services\Invoices\UpdateService;
use App\Services\PaymentProviders\FindService;

use function array_key_exists;

/**
 * Port of Rails' Invoices::Payments::StripeService — currently the
 * `update_payment_status` leg (the webhook-driven payment transition);
 * the hosted-checkout payment-url legs are TODO(port).
 *
 * Semantics ported:
 *  - the payment is found by provider_payment_id; an event for another
 *    organization's payment is ignored (shared secret key);
 *  - a missing payment with metadata payment_type "one-time" (hosted
 *    checkout) is recreated from the event when the invoice exists, is not
 *    already failed, and payment_attempts is incremented;
 *  - a missing payment with lago_invoice_id metadata but no invoice (or an
 *    already-failed invoice) is ignored;
 *  - an already-succeeded payable short-circuits;
 *  - payment.status gets the raw provider status, payable_payment_status
 *    the normalized one (payment_provider.determine_payment_status), and
 *    the invoice's payment_status follows (processing is stored as pending);
 *  - "payment.succeeded" webhook + invoice.payment_status_updated flow
 *    through the invoice update service.
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
        $result = static::makeResult('payment', 'invoice');

        $payment = Payment::query()->where('provider_payment_id', $this->stripePayment->id)->first();

        if ($payment !== null
            && $payment->payable !== null
            && $payment->payable->organization_id !== $this->organizationId) {
            return $result;
        }

        if ($payment === null && $this->stripePayment->metadataValue('payment_type') === 'one-time') {
            $payment = $this->createPaymentFromEvent($result);
        }

        if ($payment === null) {
            $payment = $this->handleMissingPayment($result);
        }

        if ($payment === null) {
            return $result;
        }

        $result->payment = $payment;
        $result->invoice = $payment->payable;
        $payable = $payment->payable;

        if ($payable instanceof Invoice && $payable->paymentSucceeded()) {
            return $result;
        }

        $payment->status = $this->status;
        $payablePaymentStatus = $payment->paymentProvider?->determinePaymentStatus($this->status);
        $payment->payable_payment_status = $payablePaymentStatus;

        if ($this->stripePayment->errorCode !== null) {
            $payment->error_code = $this->stripePayment->errorCode;
        }

        $payment->save();

        if ($payablePaymentStatus === 'succeeded') {
            SendWebhookJob::performLater('payment.succeeded', $payment);
        }

        // Rails: Integrations::Aggregator::Payments::CreateJob
        // .perform_later(payment:) if payment.should_sync_payment?.
        if ($payment->shouldSyncPayment()) {
            \App\Jobs\Integrations\Aggregator\Payments\CreateJob::dispatch($payment);
        }

        if (! $this->authenticationRetryPending($payment, $this->status)) {
            $this->updateInvoicePaymentStatus($payable, $payablePaymentStatus, processing: $this->status === 'processing');
        }

        return $result;
    }

    /**
     * Rails: authentication_retry_pending? — a 3DS failure stays pending
     * while the provider is retryable or another payment of the payable
     * awaits the customer's action.
     */
    private function authenticationRetryPending(Payment $payment, string $status): bool
    {
        if ($status !== 'failed') {
            return false;
        }

        $provider = $payment->paymentProvider;

        $retriable = $provider?->isStripe()
            && $payment->error_code === \App\Models\PaymentProvider::STRIPE_NEED_3DS_ERROR_CODE
            && ($provider->supports3ds() !== null || $this->payableSubscriptionPaymentGated($payment));

        $requiresAction = Payment::query()
            ->where('payable_type', $payment->payable_type)
            ->where('payable_id', $payment->payable_id)
            ->where('id', '!=', $payment->id)
            ->where('status', 'requires_action')
            ->exists();

        return $retriable || $requiresAction;
    }

    private function payableSubscriptionPaymentGated(Payment $payment): bool
    {
        $payable = $payment->payable;

        if ($payable instanceof Invoice) {
            return $payable->subscriptionGated();
        }

        return false;
    }

    /**
     * Rails: handle_missing_payment — only recreates a payment when the
     * event carries lago_invoice_id metadata and the invoice belongs to this
     * organization and is not already failed.
     */
    private function handleMissingPayment(BaseResult $result): ?Payment
    {
        $metadata = $this->stripePayment->metadata;

        if (! array_key_exists('lago_invoice_id', $metadata)) {
            return null;
        }

        $invoice = Invoice::query()
            ->where('id', $metadata['lago_invoice_id'])
            ->where('organization_id', $this->organizationId)
            ->first();

        if ($invoice === null) {
            return null;
        }

        if ($invoice->paymentStatusEnum() === InvoicePaymentStatus::Failed) {
            return null;
        }

        $payment = $this->createPaymentForInvoice($invoice);

        $result->payment = $payment;

        return $payment;
    }

    /** Rails: create_payment for the one-time checkout flow. */
    private function createPaymentFromEvent(BaseResult $result): ?Payment
    {
        $invoice = Invoice::query()->where('id', $this->stripePayment->metadataValue('lago_invoice_id'))->first();

        if ($invoice === null) {
            $result->notFoundFailure('invoice');

            return null;
        }

        $invoice->payment_attempts = (int) $invoice->payment_attempts + 1;
        $invoice->save();

        $payment = $this->createPaymentForInvoice($invoice);

        $result->payment = $payment;

        return $payment;
    }

    private function createPaymentForInvoice(Invoice $invoice): Payment
    {
        $customer = $invoice->customer;
        $provider = $this->paymentProviderFor($customer);
        $providerCustomer = $customer->paymentProviderCustomers()
            ->where('payment_provider_id', $provider?->id)
            ->first();

        $payment = Payment::query()->firstOrNew([
            'organization_id' => $invoice->organization_id,
            'payable_type' => 'Invoice',
            'payable_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'payment_provider_id' => $provider?->id,
            'payment_provider_customer_id' => $providerCustomer?->id,
            'amount_cents' => $this->amountCents ?? $invoice->total_due_amount_cents,
            'amount_currency' => $invoice->currency,
            'status' => 'pending',
        ]);

        $status = $provider?->determinePaymentStatus($this->stripePayment->status) ?? 'pending';
        if ($status === 'pending') {
            $status = 'processing';
        }

        $payment->provider_payment_id = $this->stripePayment->id;
        $payment->status = $this->stripePayment->status;
        $payment->payable_payment_status = $status;
        $payment->save();

        return $payment;
    }

    private function paymentProviderFor(Customer $customer): ?\App\Models\PaymentProvider
    {
        if ($customer->payment_provider === null) {
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
     * Rails: update_invoice_payment_status — processing collapses to
     * pending; ready_for_payment_processing flips off while a payment is in
     * flight or settled.
     */
    private function updateInvoicePaymentStatus(Invoice $invoice, ?string $paymentStatus, bool $processing = false): void
    {
        $status = ($paymentStatus === 'processing') ? 'pending' : (string) $paymentStatus;

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

        UpdateService::callBang(
            invoice: $invoice,
            params: $params,
            webhookNotification: true,
        );
    }
}
