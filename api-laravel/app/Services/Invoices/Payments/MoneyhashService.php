<?php

declare(strict_types=1);

namespace App\Services\Invoices\Payments;

use Throwable;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Customer;
use App\Jobs\SendWebhookJob;
use App\Services\BaseResult;
use App\Models\PaymentIntent;
use App\Services\BaseService;
use App\Services\Failures\FailedResult;
use App\Services\Invoices\UpdateService;
use App\Services\PaymentProviders\FindService;

/**
 * Port of Rails' Invoices::Payments::MoneyhashService — the webhook-driven
 * `update_payment_status` leg and the hosted-checkout `generate_payment_url`
 * leg (POST {api_base}/api/v1.1/payments/intent/ with x-Api-Key).
 *
 * update_payment_status semantics:
 *  - the payment is found-or-initialized by provider_payment_id; a new one
 *    is recreated from the event's metadata (invoice by
 *    metadata[lago_payable_id], payment_attempts incremented); a payment
 *    that cannot be built with metadata lago_payable_id but no invoice —
 *    or an already-failed invoice — is silently ignored, otherwise the
 *    result fails with "moneyhash_payment";
 *  - payment.status gets the re-normalized MH status,
 *    payable_payment_status the provider's payable map
 *    (PAYABLE_PAYMENT_STATUS_MAP);
 *  - an HTTP error on the checkout leg delivers the invoice error webhook
 *    (TODO(port): DeliverErrorWebhookService) and service-fails.
 */
class MoneyhashService extends BaseService
{
    /**
     * Rails: `update_payment_status`.
     *
     * @param  array<string, mixed>  $metadata
     */
    /**
     * The Rails services dispatch actions by name (call!(:update_payment_status, ...));
     * the port exposes them as static entrypoints instead — this instance body is
     * never invoked.
     */
    public function execute(): BaseResult
    {
        throw new \LogicException(static::class." is dispatched through its static action entrypoints");
    }

    public static function updatePaymentStatus(
        string $organizationId,
        string $providerPaymentId,
        string $status,
        ?int $amountCents = null,
        array $metadata = [],
    ): BaseResult {
        $result = static::makeResult('payment', 'invoice');

        try {
            $payment = Payment::query()->firstOrNew(['provider_payment_id' => $providerPaymentId]);

            if (! $payment->exists) {
                $payment = self::createPayment($providerPaymentId, $amountCents, $metadata, $result);

                if ($payment === null) {
                    return self::handleMissingPayment($result, $organizationId, $metadata);
                }
            }

            $result->payment = $payment;
            $result->invoice = $invoice = $payment->payable;

            if (! $invoice instanceof Invoice || $invoice->paymentSucceeded()) {
                return $result;
            }

            $provider = $payment->paymentProvider;
            $paymentStatus = $provider?->determinePaymentStatus($status);
            $payablePaymentStatus = $provider?->payablePaymentStatus($status);

            $payment->status = $paymentStatus;
            $payment->payable_payment_status = $payablePaymentStatus;
            $payment->save();

            if ($payablePaymentStatus === 'succeeded') {
                SendWebhookJob::performLater('payment.succeeded', $payment);
            }

            self::updateInvoicePaymentStatus($invoice, (string) $payablePaymentStatus, $paymentStatus === 'processing');

            return $result;
        } catch (FailedResult $e) {
            return $e->result;
        } catch (Throwable $e) {
            throw $e;
        }
    }

    /** Rails: `generate_payment_url` — a Moneyhash checkout intent. */
    public static function generatePaymentUrl(Invoice $invoice, PaymentIntent $paymentIntent): BaseResult
    {
        $result = static::makeResult('payment_url');
        $customer = $invoice->customer;
        $provider = self::paymentProviderFor($customer);
        $providerCustomer = $customer?->paymentProviderCustomers()
            ->where('payment_provider_id', $provider?->id)
            ->first();

        // Rails: should_process_payment? — succeeded/voided invoices, a
        // missing provider or a missing provider customer id skip the call.
        if ($invoice->paymentSucceeded() || $invoice->isVoided()) {
            return $result;
        }

        if ($provider === null || $providerCustomer === null
            || ($providerCustomer->provider_customer_id === null || $providerCustomer->provider_customer_id === '')) {
            return $result;
        }

        try {
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Content-Type' => 'application/json',
                'x-Api-Key' => (string) ($provider->apiKey() ?? ''),
                'X-Idempotency-Key' => $paymentIntent->id,
            ])->post(self::intentUrl(), self::paymentUrlParams($invoice, $customer, $provider, $providerCustomer, $paymentIntent));

            $response->throw();
        } catch (Throwable $e) {
            // Rails: deliver_error_webhook + service_failure (TODO(port):
            // DeliverErrorWebhookService — the invoice error webhook).
            return $result->serviceFailure(code: 'http_error', message: $e->getMessage());
        }

        $embedUrl = $response->json('data.embed_url');

        if ($embedUrl !== null) {
            $result->payment_url = $embedUrl.'?lago_request=generate_payment_url';
        }

        return $result;
    }

    /**
     * Rails: handle_missing_payment — silently ignored unless the metadata
     * carries lago_payable_id AND that invoice exists in the organization
     * AND it is not already payment-failed (then "moneyhash_payment").
     *
     * @param  array<string, mixed>  $metadata
     */
    private static function handleMissingPayment(BaseResult $result, string $organizationId, array $metadata): BaseResult
    {
        if (! array_key_exists('lago_payable_id', $metadata)) {
            return $result;
        }

        $invoice = Invoice::query()
            ->where('id', $metadata['lago_payable_id'])
            ->where('organization_id', $organizationId)
            ->first();

        if ($invoice === null) {
            return $result;
        }

        if ($invoice->paymentStatusEnum() === \App\Enums\InvoicePaymentStatus::Failed) {
            return $result;
        }

        return $result->notFoundFailure('moneyhash_payment');
    }

    /**
     * Rails: create_payment — the invoice comes from
     * metadata[lago_payable_id]; payment_attempts is incremented.
     *
     * @param  array<string, mixed>  $metadata
     */
    private static function createPayment(string $providerPaymentId, ?int $amountCents, array $metadata, BaseResult $result): ?Payment
    {
        $invoice = Invoice::query()->where('id', $metadata['lago_payable_id'] ?? null)->first();

        if ($invoice === null) {
            $result->notFoundFailure('invoice');

            return null;
        }

        $invoice->payment_attempts = (int) $invoice->payment_attempts + 1;
        $invoice->save();

        $customer = $invoice->customer;
        $provider = self::paymentProviderFor($customer);
        $providerCustomer = $customer?->paymentProviderCustomers()
            ->where('payment_provider_id', $provider?->id)
            ->first();

        return new Payment([
            'organization_id' => $invoice->organization_id,
            'payable_type' => 'Invoice',
            'payable_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'payment_provider_id' => $provider?->id,
            'payment_provider_customer_id' => $providerCustomer?->id,
            'amount_cents' => $amountCents ?? $invoice->total_amount_cents,
            'amount_currency' => mb_strtoupper((string) $invoice->currency),
            'provider_payment_id' => $providerPaymentId,
        ]);
    }

    /** Rails: payment_url_params. */
    private static function paymentUrlParams(
        Invoice $invoice,
        Customer $customer,
        \App\Models\PaymentProvider $provider,
        \App\Models\PaymentProviderCustomer $providerCustomer,
        PaymentIntent $paymentIntent,
    ): array {
        /** @var array<string, mixed> $billingData */
        $billingData = $providerCustomer->mhBillingData();
        /** @var array<string, mixed> $mhCustomFields */
        $mhCustomFields = $providerCustomer->mhCustomFields();

        $customFields = array_merge([
            'lago_payable_id' => $invoice->id,
            'lago_payable_type' => 'Invoice',
            'lago_payable_invoice_type' => $invoice->invoice_type,
            'lago_mit' => false,
            'lago_mh_service' => 'Invoices::Payments\MoneyhashService',
            'lago_request' => 'generate_payment_url',
        ], $mhCustomFields);

        $params = [
            'amount' => $invoice->total_due_amount_cents / 100,
            'amount_currency' => mb_strtoupper($invoice->currency),
            'flow_id' => $provider->flowId(),
            'billing_data' => $billingData,
            'customer' => $providerCustomer->provider_customer_id,
            'webhook_url' => self::webhookEndPoint($provider),
            'merchant_initiated' => false,
            'expires_after_seconds' => $paymentIntent->expires_at !== null
                ? max(0, $paymentIntent->expires_at->getTimestamp() - time())
                : 86400,
            'custom_fields' => $customFields,
        ];

        if ($invoice->invoice_type === 'subscription') {
            $params['custom_fields'] = array_merge($params['custom_fields'], [
                'lago_plan_id' => (string) ($invoice->subscriptions->first()?->plan_id ?? ''),
                'lago_subscription_external_id' => (string) ($invoice->subscriptions->first()?->external_id ?? ''),
            ]);
        }

        if ($providerCustomer->provider_customer_id === null || $providerCustomer->provider_customer_id === '') {
            $params = array_merge($params, [
                'tokenize_card' => true,
                'payment_type' => 'UNSCHEDULED',
                'recurring_data' => ['agreement_id' => $customer->id],
            ]);
        }

        return $params;
    }

    /** Rails: MoneyhashProvider#webhook_end_point. */
    private static function webhookEndPoint(\App\Models\PaymentProvider $provider): string
    {
        $base = mb_rtrim((string) env('LAGO_API_URL', ''), '/');

        return $base.'/webhooks/moneyhash/'.$provider->organization_id.'?code='.urlencode($provider->code);
    }

    private static function intentUrl(): string
    {
        return \App\Models\PaymentProvider::moneyhashApiBaseUrl().'/api/v1.1/payments/intent/';
    }

    /** Rails: update_invoice_payment_status(processing:). */
    private static function updateInvoicePaymentStatus(Invoice $invoice, string $paymentStatus, bool $processing = false): void
    {
        $params = [
            'payment_status' => $paymentStatus,
            'ready_for_payment_processing' => ! $processing && $paymentStatus !== 'succeeded',
        ];

        UpdateService::callBang(
            invoice: $invoice,
            params: $params,
            webhookNotification: true,
        );
    }

    /** Rails: Customers::PaymentProviderFinder#payment_provider. */
    private static function paymentProviderFor(?Customer $customer): ?\App\Models\PaymentProvider
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
}
