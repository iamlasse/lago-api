<?php

declare(strict_types=1);

namespace App\Services\PaymentRequests\Payments;

use Throwable;
use App\Models\Payment;
use App\Models\Customer;
use App\Services\BaseResult;
use App\Models\PaymentRequest;
use Illuminate\Support\Facades\Http;
use App\Models\PaymentProviderCustomer;

/**
 * Port of Rails' PaymentRequests::Payments::MoneyhashService — the payment
 * request's Moneyhash arms:
 *  - `create` (the direct create leg): charges the request over the
 *    customer's stored Moneyhash payment method (POST
 *    {api_base}/api/v1.1/payments/intent/, merchant_initiated) — a missing
 *    Moneyhash customer or payment method is a not_found, an HTTP error
 *    fails the payment request and delivers the error webhook;
 *  - `update_payment_status` (intent/transaction webhooks): the payment is
 *    found-or-initialized by provider_payment_id and recreated from the
 *    metadata when new; the payment request and its applied invoices follow
 *    the provider's payable map.
 */
class MoneyhashService extends BaseService
{
    /** Rails: `create` — charge the payment request directly. */
    public static function create(PaymentRequest $payable): BaseResult
    {
        $result = static::makeResult('payable', 'payment', 'payable_payment_status');

        $result->payable = $payable;

        $customer = $payable->customer;
        $provider = static::paymentProviderFor($customer);
        $providerCustomer = $customer->paymentProviderCustomers()
            ->where('payment_provider_id', $provider?->id)
            ->first();

        if ($providerCustomer?->provider_customer_id === null || $providerCustomer->provider_customer_id === '') {
            return $result->notFoundFailure('moneyhash_customer');
        }

        if (self::moneyhashPaymentMethodId($customer, $providerCustomer) === null) {
            return $result->notFoundFailure('payment_method');
        }

        if (! self::shouldProcessPayment($payable, $provider, $providerCustomer)) {
            return $result;
        }

        if ($payable->totalAmountCents() <= 0) {
            static::updatePayablePaymentStatus($payable, 'succeeded');

            return $result;
        }

        $payable->incrementPaymentAttempts();

        $mhResult = self::createMoneyhashPayment($payable, $customer, $provider, $providerCustomer);

        if ($mhResult === null) {
            return $result;
        }

        $mhStatus = (string) ($mhResult['data']['status'] ?? '');

        $payment = new Payment([
            'organization_id' => $payable->organization_id,
            'payable_type' => 'PaymentRequest',
            'payable_id' => $payable->id,
            'customer_id' => $payable->customer_id,
            'payment_provider_id' => $provider?->id,
            'payment_provider_customer_id' => $providerCustomer->id,
            'amount_cents' => (int) $payable->amount_cents,
            'amount_currency' => mb_strtoupper((string) $payable->amount_currency),
            'provider_payment_id' => $mhResult['data']['id'] ?? null,
            'status' => $provider?->determinePaymentStatus($mhStatus),
        ]);
        $payment->save();

        $payablePaymentStatus = $provider?->payablePaymentStatus($mhStatus);

        static::updatePayablePaymentStatus($payable, $payablePaymentStatus, processing: $payment->status === 'pending');
        static::updateInvoicesPaymentStatus($payable, $payablePaymentStatus, processing: $payment->status === 'pending');

        $result->payment = $payment;
        $result->payable_payment_status = $payablePaymentStatus;

        return $result;
    }

    /**
     * Rails: `update_payment_status` (intent / transaction webhook events).
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function updatePaymentStatus(
        string $organizationId,
        string $providerPaymentId,
        string $status,
        ?int $amountCents = null,
        array $metadata = [],
    ): BaseResult {
        $result = static::makeResult('payment', 'payable');

        try {
            $payment = Payment::query()->firstOrNew(['provider_payment_id' => $providerPaymentId]);

            if (! $payment->exists) {
                $payment = self::createPayment(
                    PaymentRequest::query()->find($metadata['lago_payable_id'] ?? null),
                    $providerPaymentId,
                    $result,
                );

                if ($payment === null) {
                    return self::handleMissingPayment($result, $organizationId, $metadata);
                }
            }

            $result->payment = $payment;
            $payable = $result->payable = $payment->payable;

            if ($payable->paymentSucceeded()) {
                return $result;
            }

            $provider = $payment->paymentProvider;

            $payment->status = $provider?->determinePaymentStatus($status);
            $payment->save();

            $paymentStatus = $provider?->payablePaymentStatus($status);
            $processing = $status === 'pending';

            static::updatePayablePaymentStatus($payable, $paymentStatus, processing: $processing);
            static::updateInvoicesPaymentStatus($payable, $paymentStatus, processing: $processing);

            static::deliverRequestedMailerIfFailed($payable);

            return $result;
        } catch (Throwable $e) {
            throw $e;
        }
    }

    /**
     * Rails: handle_missing_payment — silently ignored unless the metadata
     * carries lago_payable_id AND that payment request exists in the
     * organization AND it is not already payment-failed (then
     * "moneyhash_payment").
     *
     * @param  array<string, mixed>  $metadata
     */
    private static function handleMissingPayment(BaseResult $result, string $organizationId, array $metadata): BaseResult
    {
        if (! array_key_exists('lago_payable_id', $metadata)) {
            return $result;
        }

        $paymentRequest = PaymentRequest::query()
            ->where('id', $metadata['lago_payable_id'])
            ->where('organization_id', $organizationId)
            ->first();

        if ($paymentRequest === null) {
            return $result;
        }

        if ($paymentRequest->paymentStatus() === 'failed') {
            return $result;
        }

        return $result->notFoundFailure('moneyhash_payment');
    }

    /**
     * Rails: create_payment — the payment request comes from the metadata's
     * lago_payable_id; payment_attempts is incremented.
     */
    private static function createPayment(?PaymentRequest $payable, string $providerPaymentId, BaseResult $result): ?Payment
    {
        if ($payable === null) {
            $result->notFoundFailure('payment_request');

            return null;
        }

        $payable->incrementPaymentAttempts();

        $customer = $payable->customer;
        $provider = static::paymentProviderFor($customer);
        $providerCustomer = $customer->paymentProviderCustomers()
            ->where('payment_provider_id', $provider?->id)
            ->first();

        return new Payment([
            'organization_id' => $payable->organization_id,
            'payable_type' => 'PaymentRequest',
            'payable_id' => $payable->id,
            'customer_id' => $payable->customer_id,
            'payment_provider_id' => $provider?->id,
            'payment_provider_customer_id' => $providerCustomer?->id,
            'amount_cents' => $payable->totalAmountCents(),
            'amount_currency' => mb_strtoupper((string) $payable->amount_currency),
            'provider_payment_id' => $providerPaymentId,
        ]);
    }

    /** Rails: moneyhash_payment_method_id — the default method, else the stored one. */
    private static function moneyhashPaymentMethodId(Customer $customer, PaymentProviderCustomer $providerCustomer): ?string
    {
        return $customer->paymentMethods()->where('is_default', true)->first()?->provider_method_id
            ?? $providerCustomer->getFromSettings('payment_method_id');
    }

    /** Rails: should_process_payment?. */
    private static function shouldProcessPayment(
        PaymentRequest $payable,
        ?\App\Models\PaymentProvider $provider,
        PaymentProviderCustomer $providerCustomer,
    ): bool {
        if ($payable->paymentSucceeded()) {
            return false;
        }

        if ($provider === null) {
            return false;
        }

        return $providerCustomer->provider_customer_id !== null && $providerCustomer->provider_customer_id !== '';
    }

    /**
     * Rails: create_moneyhash_payment — a merchant-initiated intent over the
     * stored card token; an HTTP error delivers the error webhook, fails the
     * payment request and returns null.
     */
    private static function createMoneyhashPayment(
        PaymentRequest $payable,
        Customer $customer,
        ?\App\Models\PaymentProvider $provider,
        PaymentProviderCustomer $providerCustomer,
    ): ?array {
        /** @var array<string, mixed> $billingData */
        $billingData = $providerCustomer->mhBillingData();
        /** @var array<string, mixed> $mhCustomFields */
        $mhCustomFields = $providerCustomer->mhCustomFields();

        $paymentParams = [
            'amount' => $payable->totalAmountCents() / 100,
            'amount_currency' => mb_strtoupper((string) $payable->amount_currency),
            'flow_id' => $provider?->flowId(),
            'billing_data' => $billingData,
            'customer' => $providerCustomer->provider_customer_id,
            'webhook_url' => self::webhookEndPoint($provider),
            'merchant_initiated' => true,
            'payment_type' => 'UNSCHEDULED',
            'card_token' => self::moneyhashPaymentMethodId($customer, $providerCustomer),
            'recurring_data' => [
                'agreement_id' => $customer->id,
            ],
            'custom_fields' => array_merge([
                // plan/subscription
                'lago_plan_id' => (string) ($payable->invoices->first()?->subscriptions->first()?->plan_id ?? ''),
                'lago_subscription_external_id' => (string) ($payable->invoices->first()?->subscriptions->first()?->external_id ?? ''),
                // payable
                'lago_payable_id' => $payable->id,
                'lago_payable_type' => $payable->railsName(),
                // mit flag
                'lago_mit' => true,
                // service
                'lago_mh_service' => 'PaymentRequests::Payments::MoneyhashService',
                // request
                'lago_request' => 'create_payment_request',
            ], $mhCustomFields),
        ];

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'x-Api-Key' => (string) ($provider?->apiKey() ?? ''),
            ])->post(self::intentUrl(), $paymentParams);

            $response->throw();
        } catch (Throwable $e) {
            DeliverErrorWebhookService::callAsync($payable, [
                'provider_customer_id' => $providerCustomer->provider_customer_id,
                'provider_error' => [
                    'message' => $e->getMessage(),
                    'error_code' => 'http_error',
                ],
            ]);

            static::updatePayablePaymentStatus($payable, 'failed', deliverWebhook: false);

            return null;
        }

        /** @var array<string, mixed> */
        return $response->json() ?? [];
    }

    /** Rails: MoneyhashProvider#webhook_end_point. */
    private static function webhookEndPoint(?\App\Models\PaymentProvider $provider): string
    {
        $base = mb_rtrim((string) env('LAGO_API_URL', ''), '/');

        return $base.'/webhooks/moneyhash/'.($provider?->organization_id ?? '').'?code='.urlencode((string) $provider?->code);
    }

    private static function intentUrl(): string
    {
        return \App\Models\PaymentProvider::moneyhashApiBaseUrl().'/api/v1.1/payments/intent/';
    }
}
