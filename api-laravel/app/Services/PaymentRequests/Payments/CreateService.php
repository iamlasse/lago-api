<?php

declare(strict_types=1);

namespace App\Services\PaymentRequests\Payments;

use App\Models\Payment;
use App\Models\Customer;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\PaymentRequest;
use App\Models\PaymentProviderCustomer;
use App\Services\Failures\ServiceFailure;
use App\Jobs\PaymentRequests\PaymentsCreateJob;
use App\Services\Invoices\Payments\AlreadyPaidError;
use App\Services\PaymentProviders\CreatePaymentFactory;

/**
 * Port of Rails' PaymentRequests::Payments::CreateService — the automatic
 * charge attempt a payment request triggers when it is created
 * (PaymentRequests::Payments::CreateService.call_async).
 *
 * Ported flow: a missing payment provider is a not_found; the skip
 * conditions (succeeded request, no provider, no provider customer id, an
 * invoice not ready for payment processing); a zero-amount request is
 * marked succeeded directly; an in-flight processing payment
 * short-circuits; otherwise the pending payment row is created
 * (payment_attempts incremented) and the provider charge runs through
 * PaymentProviders::CreatePaymentFactory with the lago_payable_type
 * metadata, then the payment request and its applied invoices follow the
 * payment.
 *
 * Error handling mirrors Rails: AlreadyPaidError drops the unused pending
 * payment; a provider ServiceFailure delivers the
 * payment_request.payment_failure webhook and fails the payment request.
 *
 * TODO(port): PaymentRequestMailer.requested (mailer slice), the dunning
 * campaign reset, and the provider create legs other than Stripe (the
 * factory currently covers Stripe; the Adyen / GoCardless / Cashfree /
 * Moneyhash arms exist for invoice payables and are wired through the same
 * factory).
 */
class CreateService extends BaseService
{
    public function __construct(
        private readonly PaymentRequest $payable,
        private readonly ?string $paymentProvider = null,
        private readonly array $paymentMethodParams = [],
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('payable', 'payment', 'payment_provider');

        $payable = $this->payable;
        $result->payable = $payable;

        $customer = $payable->customer;
        $providerSlug = $this->paymentProvider ?? $customer->payment_provider;

        if ($providerSlug === null || $providerSlug === '') {
            return $result->notFoundFailure('payment_provider');
        }

        $provider = self::paymentProviderFor($customer);

        if ($provider === null) {
            return $result->notFoundFailure('payment_provider');
        }

        $result->payment_provider = $providerSlug;

        if (! $this->shouldProcessPayment($customer, $provider)) {
            return $result;
        }

        if ($payable->totalAmountCents() <= 0) {
            static::updatePayablePaymentStatus($payable, 'succeeded');

            return $result;
        }

        $providerCustomer = $this->currentPaymentProviderCustomer($customer, $provider);

        $processingPayment = Payment::query()
            ->where('payable_type', 'PaymentRequest')
            ->where('payable_id', $payable->id)
            ->where('payment_provider_id', $provider->id)
            ->where('payment_provider_customer_id', $providerCustomer?->id)
            ->where('amount_cents', $payable->totalAmountCents())
            ->where('amount_currency', $payable->amount_currency)
            ->where('payable_payment_status', 'processing')
            ->first();

        if ($processingPayment !== null) {
            // Payment is being processed — the webhooks will advance it.
            $result->payment = $processingPayment;

            return $result;
        }

        $payable->incrementPaymentAttempts();

        /** @var Payment $payment */
        $payment = Payment::query()->firstOrCreate([
            'payable_type' => 'PaymentRequest',
            'payable_id' => $payable->id,
            'payable_payment_status' => 'pending',
        ], [
            'organization_id' => $payable->organization_id,
            'payment_provider_id' => $provider->id,
            'payment_provider_customer_id' => $providerCustomer?->id,
            'amount_cents' => $payable->totalAmountCents(),
            'amount_currency' => $payable->amount_currency,
            'status' => 'pending',
            'customer_id' => $payable->customer_id,
        ]);

        $payment->payment_method_id = self::determinePaymentMethod($customer, $this->paymentMethodParams)?->id;
        $payment->save();

        $result->payment = $payment;

        try {
            $paymentResult = CreatePaymentFactory::newInstance(
                provider: $providerSlug,
                payment: $payment,
                reference: self::paymentReference($payable),
                metadata: [
                    'lago_customer_id' => $payable->customer_id,
                    'lago_payable_id' => $payable->id,
                    'lago_payable_type' => $payable->railsName(),
                ],
            )->callOrFail();
        } catch (AlreadyPaidError) {
            // The payment request was settled by another payment so we can
            // drop the unused pending payment. Reload from the DB before
            // destroying: a concurrent attempt shares this same row and may
            // have already advanced it.
            $persistedPayment = Payment::query()->find($payment->id);

            if ($persistedPayment !== null
                && $persistedPayment->provider_payment_id === null
                && $persistedPayment->payablePaymentStatus() !== 'succeeded') {
                $persistedPayment->delete();
            }

            $result->payment = null;

            return $result;
        } catch (ServiceFailure $e) {
            $paymentResult = $e->result;

            static::deliverRequestedMailerIfFailed($payable);

            $result->payment = $paymentResult->payment ?? $payment;

            DeliverErrorWebhookService::callAsync($payable, [
                'provider_customer_id' => $providerCustomer?->provider_customer_id,
                'provider_error' => [
                    'message' => $paymentResult->error_message,
                    'error_code' => $paymentResult->error_code,
                ],
            ]);

            static::updatePayablePaymentStatus(
                $payable,
                $result->payment->payablePaymentStatus(),
            );

            // TODO(port): `raise if e.result.reraise` — RetriableError
            // propagation for the job-level retries.
        }

        $paymentStatus = $paymentResult->payment->payablePaymentStatus();

        static::updatePayablePaymentStatus($payable, $paymentStatus);
        static::updateInvoicesPaymentStatus($payable, $paymentStatus);
        static::updateInvoicesPaidAmountCents($payable, $paymentStatus);

        if ($payable->organization->issueReceiptsEnabled()) {
            \App\Jobs\PaymentReceipts\CreateJob::dispatch($payment);
        }

        static::deliverRequestedMailerIfFailed($payable);

        return $result;
    }

    /** Port of `call_async` — the payment-request creation dispatch. */
    public function callAsync(): BaseResult
    {
        $result = static::makeResult('payable', 'payment', 'payment_provider');

        $provider = $this->paymentProvider ?? $this->payable->customer->payment_provider;

        if ($provider === null) {
            return $result->notFoundFailure('payment_provider');
        }

        PaymentsCreateJob::dispatch($this->payable, $provider, $this->paymentMethodParams);

        $result->payment_provider = $provider;

        return $result;
    }

    /**
     * Rails: determine_payment_method — the payment_method override
     * ("manual" means no stored method), else the customer's default.
     *
     * @param  array<string, mixed>  $paymentMethodParams
     */
    private static function determinePaymentMethod(Customer $customer, array $paymentMethodParams): ?\App\Models\PaymentMethod
    {
        if ($paymentMethodParams !== []) {
            return self::determineOverridePaymentMethod($customer, $paymentMethodParams);
        }

        return $customer->paymentMethods()->where('is_default', true)->first();
    }

    /**
     * Rails: determine_override_payment_method.
     *
     * @param  array<string, mixed>  $paymentMethodParams
     */
    private static function determineOverridePaymentMethod(Customer $customer, array $paymentMethodParams): ?\App\Models\PaymentMethod
    {
        if (($paymentMethodParams['payment_method_type'] ?? null) === 'manual') {
            return null;
        }

        if (($paymentMethodParams['payment_method_id'] ?? null) !== null) {
            return $customer->paymentMethods()
                ->where('id', $paymentMethodParams['payment_method_id'])
                ->first();
        }

        return $customer->paymentMethods()->where('is_default', true)->first();
    }

    /**
     * Rails: payment_reference — mirrors the checkout-link description built
     * by PaymentRequests::Payments::StripeService.
     */
    private static function paymentReference(PaymentRequest $payable): string
    {
        $reference = ($payable->customer->billingEntity?->name ?? '').' - Overdue invoices';

        if ($payable->invoices->count() === 1) {
            return $reference.': '.$payable->invoices->first()->number;
        }

        return $reference;
    }

    /**
     * Rails: should_process_payment? — a succeeded request is skipped, the
     * customer must have a provider and a provider customer id, and every
     * applied invoice must be ready for payment processing.
     */
    private function shouldProcessPayment(Customer $customer, ?\App\Models\PaymentProvider $provider): bool
    {
        $payable = $this->payable;

        if ($payable->paymentSucceeded()) {
            return false;
        }

        if ($provider === null) {
            return false;
        }

        $providerCustomer = $this->currentPaymentProviderCustomer($customer, $provider);

        if ($providerCustomer === null || $providerCustomer->provider_customer_id === null
            || $providerCustomer->provider_customer_id === '') {
            return false;
        }

        return $payable->invoices->every(fn ($invoice): bool => (bool) $invoice->ready_for_payment_processing);
    }

    private function currentPaymentProviderCustomer(Customer $customer, \App\Models\PaymentProvider $provider): ?PaymentProviderCustomer
    {
        return $customer->paymentProviderCustomers()
            ->where('payment_provider_id', $provider->id)
            ->first();
    }
}
