<?php

declare(strict_types=1);

namespace App\Services\PaymentRequests\Payments;

use Throwable;
use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Services\BaseResult;
use App\Values\FlutterwavePayment;
use Illuminate\Support\Facades\Http;

/**
 * Port of Rails' PaymentRequests::Payments::FlutterwaveService — the
 * payment request's Flutterwave arms:
 *  - `update_payment_status` (charge-completed webhooks): the one-time
 *    metadata recreates the payment row, otherwise the payment is found by
 *    provider_payment_id; the payment request and its applied invoices
 *    follow the normalized status;
 *  - `generate_payment_url`: a hosted checkout link (POST /payments).
 */
class FlutterwaveService extends BaseService
{
    /** Rails: `update_payment_status`. */
    public static function updatePaymentStatus(
        string $status,
        FlutterwavePayment $flutterwavePayment,
        ?int $amountCents = null,
    ): BaseResult {
        $result = static::makeResult('payment', 'payable');

        $payment = $flutterwavePayment->metadataValue('payment_type') === 'one-time'
            ? self::createPayment($flutterwavePayment)
            : Payment::query()->where('provider_payment_id', $flutterwavePayment->id)->first();

        if ($payment === null) {
            return $result->notFoundFailure('flutterwave_payment');
        }

        return static::updatePaymentAndPayable($result, $payment, $status);
    }

    /** Rails: `generate_payment_url` — a hosted checkout link. */
    public static function generatePaymentUrl(PaymentRequest $payable): BaseResult
    {
        $result = static::makeResult('payment_url');
        $customer = $payable->customer;
        $organization = $payable->organization;
        $provider = static::paymentProviderFor($customer);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.(string) ($provider?->secretKey() ?? ''),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post(\App\Models\PaymentProvider::FLUTTERWAVE_API_URL.'/payments', [
                // Rails: Money.from_cents(total_amount_cents, currency).to_f.
                'amount' => $payable->totalAmountCents() / 100,
                'tx_ref' => 'lago_payment_request_'.$payable->id,
                'currency' => mb_strtoupper((string) $payable->amount_currency),
                'redirect_url' => self::successRedirectUrl($provider),
                'customer' => [
                    'email' => $customer->email,
                    'phone_number' => $customer->phone ?? '',
                    'name' => $customer->name ?? $customer->email,
                ],
                'customizations' => array_filter([
                    'title' => $organization->name.' - Payment Request',
                    'description' => 'Payment for invoices: '.self::invoiceNumbers($payable),
                    'logo' => $organization->logo_url,
                ]),
                'configuration' => [
                    'session_duration' => 30,
                ],
                'meta' => [
                    'lago_customer_id' => $customer->id,
                    'lago_payment_request_id' => $payable->id,
                    'lago_organization_id' => $organization->id,
                    'lago_invoice_ids' => $payable->invoices->pluck('id')->implode(','),
                ],
            ]);

            $response->throw();
        } catch (Throwable $e) {
            // Rails: rescue LagoHttpClient::HttpError -> deliver the error
            // webhook, then service_failure.
            static::deliverErrorWebhook($payable, $provider, $e->getMessage(), 'http_error');

            return $result->serviceFailure(code: 'action_script_runtime_error', message: $e->getMessage());
        }

        $result->payment_url = $response->json('data.link');

        return $result;
    }

    /**
     * Rails: create_payment — the one-time settlement recreates the payment
     * row (payment_attempts incremented).
     */
    private static function createPayment(FlutterwavePayment $flutterwavePayment): ?Payment
    {
        $payable = PaymentRequest::query()->find($flutterwavePayment->metadataValue('lago_payable_id'));

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
            'provider_payment_id' => $flutterwavePayment->id,
        ]);
        $payment->save();

        return $payment;
    }

    /** Rails: invoice_numbers — the applied invoices' numbers. */
    private static function invoiceNumbers(PaymentRequest $payable): string
    {
        return $payable->invoices->pluck('number')->implode(', ');
    }

    /** Rails: success_redirect_url. */
    private static function successRedirectUrl(?\App\Models\PaymentProvider $provider): string
    {
        return (string) ($provider?->successRedirectUrl() ?: \App\Models\PaymentProvider::FLUTTERWAVE_SUCCESS_REDIRECT_URL);
    }

    /**
     * Rails: deliver_error_webhook — only when the organization has webhook
     * endpoints (SendWebhookJob.perform_later's guard).
     */
    private static function deliverErrorWebhook(
        PaymentRequest $payable,
        ?\App\Models\PaymentProvider $provider,
        string $message,
        string $errorCode,
    ): void {
        \App\Jobs\SendWebhookJob::performLater('payment_request.payment_failure', $payable, [
            'provider_customer_id' => $payable->customer->paymentProviderCustomers()
                ->where('payment_provider_id', $provider?->id)
                ->first()?->provider_customer_id,
            'provider_error' => [
                'message' => $message,
                'error_code' => $errorCode,
            ],
        ]);
    }
}
