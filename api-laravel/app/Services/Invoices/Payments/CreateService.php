<?php

declare(strict_types=1);

namespace App\Services\Invoices\Payments;

use App\Models\Invoice;
use App\Models\Payment;
use App\Jobs\SendWebhookJob;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\PaymentProviderCustomer;
use App\Jobs\Invoices\PaymentsCreateJob;
use App\Services\Invoices\UpdateService;
use App\Services\PaymentProviders\FindService;
use App\Services\PaymentMethods\DetermineService;
use App\Services\PaymentProviders\Stripe\Payments\CreateService as StripeCreateService;

use function in_array;

/**
 * Port of Rails' Invoices::Payments::CreateService — the automatic payment
 * attempt an invoice triggers when it is finalized (the
 * Invoices::Payments::CreateService.call_async seam in the invoicing
 * pipeline).
 *
 * Ported flow: skip conditions (self-billed, already succeeded/voided/
 * closed, no provider, no provider customer id, no payment method) — a
 * zero-amount invoice is marked succeeded directly; an in-flight
 * processing payment short-circuits; otherwise the payment row is created
 * (payment_attempts incremented) and the provider charge runs (Stripe:
 * POST /v1/payment_intents), then the invoice's payment_status follows the
 * payment.
 *
 * Error handling mirrors Rails: AlreadyPaidError drops the unused pending
 * payment; identified provider failures update the invoice payment status,
 * deliver the payment_provider error webhook and either raise RetriableError
 * (should_retry) or re-raise (reraise); RateLimit/Connection failures
 * propagate for job-level retries.
 *
 * TODO(port): Integrations::Aggregator::Payments::CreateJob (accounting
 * sync), the checkout auto-payment delay (CHECKOUT_AUTO_PAYMENT_DELAY,
 * deferred while open hosted-checkout payment intents exist),
 * DeliverErrorWebhookService's error_details serialization, and the
 * CreatePaymentFactory legs for adyen / cashfree / gocardless / moneyhash.
 */
class CreateService extends BaseService
{
    public function __construct(
        private readonly Invoice $invoice,
        private readonly ?string $paymentProvider = null,
        private readonly array $paymentMethodParams = [],
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('invoice', 'payment', 'payment_provider');

        $invoice = $this->invoice;
        $result->invoice = $invoice;

        $provider = $this->currentPaymentProvider($result);

        if (! $this->shouldProcessPayment($result, $provider)) {
            return $result;
        }

        /** @var \App\Models\PaymentProvider $provider */
        $providerCustomer = $this->currentPaymentProviderCustomer($provider);

        if ($invoice->total_amount_cents <= 0) {
            $this->updateInvoicePaymentStatus($invoice, 'succeeded');

            return $result;
        }

        $processingPayment = Payment::query()
            ->where('payable_type', 'Invoice')
            ->where('payable_id', $invoice->id)
            ->where('payment_provider_id', $provider->id)
            ->where('payment_provider_customer_id', $providerCustomer->id)
            ->where('amount_cents', $invoice->total_amount_cents)
            ->where('amount_currency', $invoice->currency)
            ->where('payable_payment_status', 'processing')
            ->first();

        if ($processingPayment !== null) {
            // Payment is being processed — the webhooks will advance it.
            $result->payment = $processingPayment;

            return $result;
        }

        $invoice->payment_attempts = (int) $invoice->payment_attempts + 1;
        $invoice->save();

        /** @var Payment $payment */
        $payment = Payment::query()->firstOrCreate([
            'payable_type' => 'Invoice',
            'payable_id' => $invoice->id,
            'payable_payment_status' => 'pending',
        ], [
            'organization_id' => $invoice->organization_id,
            'payment_provider_id' => $provider->id,
            'payment_provider_customer_id' => $providerCustomer->id,
            'amount_cents' => $invoice->totalDueAmountCents(),
            'amount_currency' => $invoice->currency,
            'status' => 'pending',
            'customer_id' => $invoice->customer_id,
        ]);

        $payment->payment_method_id = DetermineService::call(
            invoice: $invoice,
            customer: $invoice->customer,
            paymentMethodParams: $this->paymentMethodParams,
        )->payment_method?->id;
        $payment->save();

        $result->payment = $payment;

        try {
            $paymentResult = $this->createProviderPayment($payment, $invoice, $providerCustomer);
        } catch (AlreadyPaidError) {
            // The invoice was settled by another payment path — drop the
            // unused pending payment. Reload from the DB first: a concurrent
            // attempt shares this row and may have advanced it.
            $persistedPayment = Payment::query()->find($payment->id);

            if ($persistedPayment !== null
                && $persistedPayment->provider_payment_id === null
                && $persistedPayment->payablePaymentStatus() !== 'succeeded') {
                $persistedPayment->delete();
            }

            $result->payment = null;

            return $result;
        } catch (RateLimitError|ConnectionError $e) {
            throw $e;
        }

        // TODO(port): deliver the payment_provider error webhook + the
        // should_retry/reraise rescue legs (see createProviderPayment) once
        // the remaining provider create services exist; Stripe failures
        // already flow through them today.

        $paymentStatus = $paymentResult->payment->payablePaymentStatus();
        $this->updateInvoicePaymentStatus($invoice, $paymentStatus);

        // TODO(port): Integrations::Aggregator::Payments::CreateJob when
        // result.payment.should_sync_payment?.

        return $result;
    }

    /**
     * Port of `call_async` — the invoice-finalize dispatch. The checkout
     * auto-payment delay (Rails: CHECKOUT_AUTO_PAYMENT_DELAY, 10 minutes,
     * when the organization has open hosted-checkout payment intents) is
     * TODO(port) with the payment-url slice; the job runs on the payments
     * queue otherwise.
     */
    public function callAsync(): BaseResult
    {
        $result = static::makeResult('invoice', 'payment', 'payment_provider');

        $provider = $this->paymentProvider ?: $this->invoice->customer->payment_provider;

        if ($provider === null) {
            return $result;
        }

        PaymentsCreateJob::dispatch($this->invoice, $provider, $this->paymentMethodParams);

        $result->payment_provider = $provider;

        return $result;
    }

    /**
     * Runs the provider charge (Rails: CreatePaymentFactory.new_instance
     * .call!) and applies the shared failure handling: skip the error
     * webhook for pending payments and the amount_too_small / 3DS error
     * codes, update the invoice payment status (pending when retriable),
     * then raise RetriableError (job retry) or re-raise.
     */
    private function createProviderPayment(
        Payment $payment,
        Invoice $invoice,
        PaymentProviderCustomer $providerCustomer,
    ): BaseResult {
        $reference = $invoice->subscriptionGated()
            ? $invoice->billingEntity->name.' - Invoice '.$invoice->id
            : $invoice->billingEntity->name.' - Invoice '.$invoice->number;

        $metadata = [
            'lago_invoice_id' => $invoice->id,
            'lago_customer_id' => $invoice->customer_id,
            'invoice_issuing_date' => $invoice->issuing_date?->toDateString(),
            'invoice_type' => $invoice->typeEnum()?->label(),
        ];

        try {
            return (new StripeCreateService(payment: $payment, reference: $reference, metadata: $metadata))
                ->callOrFail();
        } catch (RateLimitError|ConnectionError|AlreadyPaidError $e) {
            throw $e;
        } catch (\App\Services\Failures\ServiceFailure $e) {
            $paymentResult = $e->result;
            $payment = $paymentResult->payment;

            if (! $this->skipErrorWebhook($payment, $e)) {
                SendWebhookJob::performLater('invoice.payment_provider_error', $invoice, [
                    'provider_customer_id' => $providerCustomer->provider_customer_id,
                    'provider_error' => [
                        'message' => $paymentResult->error_message,
                        'error_code' => $paymentResult->error_code,
                    ],
                ]);
            }

            $this->updateInvoicePaymentStatus(
                $invoice,
                $paymentResult->should_retry ? 'pending' : $payment->payablePaymentStatus(),
            );

            if ($paymentResult->should_retry) {
                throw new RetriableError($e->getMessage(), previous: $e);
            }

            if ($paymentResult->reraise) {
                throw $e;
            }

            return $paymentResult;
        }
    }

    /** Rails: skip_error_webhook?. */
    private function skipErrorWebhook(Payment $payment, \App\Services\Failures\ServiceFailure $e): bool
    {
        if ($payment->payablePaymentStatus() === 'pending') {
            return true;
        }

        return in_array($payment->error_code, [
            \App\Models\PaymentProvider::STRIPE_AMOUNT_TOO_SMALL_ERROR_CODE,
            \App\Models\PaymentProvider::STRIPE_NEED_3DS_ERROR_CODE,
        ], true);
    }

    /**
     * Rails: should_process_payment? — self-billed / settled / voided /
     * closed invoices are skipped, the customer must have a provider, a
     * provider customer id and a payment method (or a Stripe
     * customer_balance-only connection, which needs no instrument).
     */
    private function shouldProcessPayment(BaseResult $result, ?\App\Models\PaymentProvider $provider): bool
    {
        $invoice = $this->invoice;

        if ($provider === null) {
            return false;
        }

        if ($invoice->self_billed) {
            return false;
        }

        if ($invoice->paymentSucceeded() || $invoice->isVoided() || $invoice->isClosed()) {
            return false;
        }

        $providerCustomer = $this->currentPaymentProviderCustomer($provider);

        if ($providerCustomer->exists
            && ($providerCustomer->provider_customer_id === null || $providerCustomer->provider_customer_id === '')) {
            return false;
        }

        if (! $providerCustomer->exists) {
            return false;
        }

        $paymentMethod = DetermineService::call(
            invoice: $invoice,
            customer: $invoice->customer,
            paymentMethodParams: $this->paymentMethodParams,
        )->payment_method;

        if ($paymentMethod !== null) {
            return true;
        }

        // Rails: stripe_customer_balance_only? — a customer_balance-only
        // Stripe connection is chargeable without a stored instrument.
        $methods = $providerCustomer->getFromSettings('provider_payment_methods');

        return $providerCustomer->type === 'PaymentProviderCustomers::StripeCustomer' && $methods === ['customer_balance'];
    }

    private function currentPaymentProvider(BaseResult $result): ?\App\Models\PaymentProvider
    {
        if (isset($result->payment_provider) && $result->payment_provider !== null) {
            return $result->payment_provider;
        }

        $slug = $this->paymentProvider ?: $this->invoice->customer->payment_provider;

        if ($slug === null || $slug === '') {
            return null;
        }

        $customer = $this->invoice->customer;
        $findResult = FindService::call(
            organizationId: $customer->organization_id,
            code: $customer->payment_provider_code,
            paymentProviderType: $slug,
        );

        if ($findResult->failure()) {
            return null;
        }

        $result->payment_provider = $findResult->payment_provider;

        return $findResult->payment_provider;
    }

    private function currentPaymentProviderCustomer(\App\Models\PaymentProvider $provider): PaymentProviderCustomer
    {
        return $this->invoice->customer->paymentProviderCustomers()
            ->where('payment_provider_id', $provider->id)
            ->first() ?? new PaymentProviderCustomer();
    }

    /**
     * Rails: update_invoice_payment_status — processing collapses to
     * pending; ready_for_payment_processing is true only for pending/failed.
     */
    private function updateInvoicePaymentStatus(Invoice $invoice, ?string $paymentStatus): void
    {
        $params = [
            'payment_status' => $paymentStatus === 'processing' ? 'pending' : (string) $paymentStatus,
            'ready_for_payment_processing' => in_array((string) $paymentStatus, ['pending', 'failed'], true),
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
            webhookNotification: $paymentStatus === 'succeeded',
        );
    }
}
