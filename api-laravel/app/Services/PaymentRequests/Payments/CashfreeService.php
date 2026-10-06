<?php

declare(strict_types=1);

namespace App\Services\PaymentRequests\Payments;

use Throwable;
use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Services\BaseResult;
use App\Values\CashfreePayment;
use Illuminate\Support\Facades\Http;

/**
 * Port of Rails' PaymentRequests::Payments::CashfreeService — the payment
 * request's Cashfree arms:
 *  - `update_payment_status` (payment-link PAID webhooks): the one-time
 *    link notes recreate the payment row, otherwise the payment is found by
 *    provider_payment_id; the payment request and its applied invoices
 *    follow the normalized status;
 *  - `generate_payment_url`: a one-time payment link (POST {base}/links).
 */
class CashfreeService extends BaseService
{
    public const PROVIDER_NAME = 'Cashfree';

    /** Rails: `update_payment_status`. */
    public static function updatePaymentStatus(
        string $status,
        CashfreePayment $cashfreePayment,
        ?int $amountCents = null,
    ): BaseResult {
        $result = static::makeResult('payment', 'payable');

        $payment = $cashfreePayment->metadataValue('payment_type') === 'one-time'
            ? self::createPayment($cashfreePayment)
            : Payment::query()->where('provider_payment_id', $cashfreePayment->id)->first();

        if ($payment === null) {
            return $result->notFoundFailure('cashfree_payment');
        }

        return static::updatePaymentAndPayable($result, $payment, $status);
    }

    /** Rails: `generate_payment_url` — a payment link for the request. */
    public static function generatePaymentUrl(PaymentRequest $payable): BaseResult
    {
        $result = static::makeResult('payment_url');
        $customer = $payable->customer;
        $provider = static::paymentProviderFor($customer);

        try {
            $response = Http::withHeaders([
                'accept' => 'application/json',
                'content-type' => 'application/json',
                'x-client-id' => (string) ($provider?->clientId() ?? ''),
                'x-client-secret' => (string) ($provider?->clientSecret() ?? ''),
                'x-api-version' => \App\Models\PaymentProvider::CASHFREE_API_VERSION,
            ])->post(\App\Models\PaymentProvider::cashfreeBaseUrl(), self::paymentUrlParams($payable, $customer));

            $response->throw();
        } catch (Throwable $e) {
            // Rails: rescue LagoHttpClient::HttpError -> third_party_failure.
            return $result->thirdPartyFailure(
                thirdParty: self::PROVIDER_NAME,
                errorCode: 'http_error',
                errorMessage: $e->getMessage(),
            );
        }

        $result->payment_url = $response->json('link_url');

        return $result;
    }

    /**
     * Rails: create_payment — the one-time link settlement recreates the
     * payment row (payment_attempts incremented).
     */
    private static function createPayment(CashfreePayment $cashfreePayment): ?Payment
    {
        $payable = PaymentRequest::query()->find($cashfreePayment->metadataValue('lago_payable_id'));

        if ($payable === null) {
            return null;
        }

        $payable->incrementPaymentAttempts();

        $customer = $payable->customer;
        $provider = static::paymentProviderFor($customer);
        $providerCustomer = $customer->paymentProviderCustomers()
            ->where('payment_provider_id', $provider?->id)
            ->first();

        $payment = new Payment([
            'organization_id' => $payable->organization_id,
            'payable_type' => 'PaymentRequest',
            'payable_id' => $payable->id,
            'customer_id' => $payable->customer_id,
            'payment_provider_id' => $provider?->id,
            'payment_provider_customer_id' => $providerCustomer?->id,
            'amount_cents' => $payable->totalAmountCents(),
            'amount_currency' => mb_strtoupper((string) $payable->amount_currency),
            'provider_payment_id' => $cashfreePayment->id,
        ]);
        $payment->save();

        return $payment;
    }

    /** Rails: payment_url_params. */
    private static function paymentUrlParams(PaymentRequest $payable, \App\Models\Customer $customer): array
    {
        $provider = static::paymentProviderFor($customer);

        return [
            'customer_details' => [
                'customer_phone' => $customer->phone ?? '9999999999',
                'customer_email' => $customer->email,
                'customer_name' => $customer->name,
            ],
            'link_notify' => [
                'send_sms' => false,
                'send_email' => false,
            ],
            'link_meta' => [
                'upi_intent' => true,
                'return_url' => self::successRedirectUrl($provider),
            ],
            'link_notes' => [
                'lago_customer_id' => $customer->id,
                'lago_payable_id' => $payable->id,
                'lago_payable_type' => $payable->railsName(),
                'payment_issuing_date' => $payable->created_at?->toISOString(),
                'payment_type' => 'one-time',
            ],
            'link_id' => \Illuminate\Support\Str::uuid().'.'.$payable->payment_attempts,
            'link_amount' => $payable->totalAmountCents() / 100,
            'link_currency' => mb_strtoupper((string) $payable->amount_currency),
            'link_purpose' => $payable->id,
            'link_expiry_time' => now()->addMinutes(10)->toISOString(),
            'link_partial_payments' => false,
            'link_auto_reminders' => false,
        ];
    }

    /** Rails: success_redirect_url. */
    private static function successRedirectUrl(?\App\Models\PaymentProvider $provider): string
    {
        return (string) ($provider?->successRedirectUrl() ?: \App\Models\PaymentProvider::CASHFREE_SUCCESS_REDIRECT_URL);
    }
}
