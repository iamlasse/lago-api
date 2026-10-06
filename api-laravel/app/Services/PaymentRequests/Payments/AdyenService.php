<?php

declare(strict_types=1);

namespace App\Services\PaymentRequests\Payments;

use App\Models\Payment;
use App\Services\BaseResult;
use App\Models\PaymentRequest;
use App\Services\PaymentProviders\Adyen\Client;
use App\Services\PaymentProviders\Adyen\AdyenError;

/**
 * Port of Rails' PaymentRequests::Payments::AdyenService — the payment
 * request's Adyen arms:
 *  - `update_payment_status` (AUTHORISATION / CANCELLATION webhooks): the
 *    one-time checkout metadata recreates the payment row, otherwise the
 *    payment is found by provider_payment_id; the payment request and its
 *    applied invoices follow the normalized status;
 *  - `generate_payment_url` (POST /paymentLinks): a one-time payment link
 *    over the request's total amount.
 */
class AdyenService extends BaseService
{
    public const PROVIDER_NAME = 'Adyen';

    /** Rails: `update_payment_status`. */
    public static function updatePaymentStatus(
        string $providerPaymentId,
        string $status,
        ?int $amountCents = null,
        array $metadata = [],
    ): BaseResult {
        $result = static::makeResult('payment', 'payable');

        $payment = ($metadata['payment_type'] ?? null) === 'one-time'
            ? self::createPayment($metadata)
            : Payment::query()->where('provider_payment_id', $providerPaymentId)->first();

        if ($payment === null) {
            return $result->notFoundFailure('adyen_payment');
        }

        return static::updatePaymentAndPayable($result, $payment, $status);
    }

    /** Rails: `generate_payment_url` — a payment link for the request. */
    public static function generatePaymentUrl(PaymentRequest $payable): BaseResult
    {
        $result = static::makeResult('payment_url');
        $customer = $payable->customer;
        $provider = static::paymentProviderFor($customer);

        $client = new Client(
            apiKey: (string) ($provider?->apiKey() ?? ''),
            environment: $provider?->adyenStyleEnvironment() ?? 'test',
            livePrefix: (string) ($provider?->livePrefix() ?? ''),
        );

        try {
            [$status, $response] = $client->call('post', 'paymentLinks', self::paymentUrlParams($payable, $customer, $provider));
        } catch (AdyenError $e) {
            return $result->thirdPartyFailure(
                thirdParty: self::PROVIDER_NAME,
                errorCode: $e->code,
                errorMessage: $e->msg,
            );
        }

        if (Client::responseFailed($status)) {
            $error = Client::errorFromResponse($status, $response);

            return $result->serviceFailure(code: $error->code, message: $error->msg);
        }

        $result->payment_url = $response['url'] ?? null;

        return $result;
    }

    /**
     * Rails: create_payment — the one-time checkout settlement recreates the
     * payment row (payment_attempts incremented).
     *
     * @param  array<string, mixed>  $metadata
     */
    private static function createPayment(array $metadata): ?Payment
    {
        $payable = PaymentRequest::query()->find($metadata['lago_payable_id'] ?? null);

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
        ]);
        $payment->save();

        return $payment;
    }

    /** Rails: payment_url_params. */
    private static function paymentUrlParams(
        PaymentRequest $payable,
        \App\Models\Customer $customer,
        \App\Models\PaymentProvider $provider,
    ): array {
        $params = [
            'reference' => 'Overdue invoices',
            'amount' => [
                'value' => $payable->totalAmountCents(),
                'currency' => mb_strtoupper((string) $payable->amount_currency),
            ],
            'merchantAccount' => $provider->merchantAccount(),
            'returnUrl' => self::successRedirectUrl($provider),
            'shopperReference' => $customer->external_id,
            'storePaymentMethodMode' => 'enabled',
            'recurringProcessingModel' => 'UnscheduledCardOnFile',
            // max link TTL
            'expiresAt' => now()->addDays(70)->toISOString(),
            'metadata' => [
                'lago_customer_id' => $customer->id,
                'lago_payable_id' => $payable->id,
                'lago_payable_type' => $payable->railsName(),
                'payment_type' => 'one-time',
            ],
        ];

        if (($customer->email ?? null) !== null && $customer->email !== '') {
            $params['shopperEmail'] = $customer->email;
        }

        return $params;
    }

    /** Rails: success_redirect_url. */
    private static function successRedirectUrl(\App\Models\PaymentProvider $provider): string
    {
        return (string) ($provider->successRedirectUrl() ?: \App\Models\PaymentProvider::ADYEN_SUCCESS_REDIRECT_URL);
    }
}
