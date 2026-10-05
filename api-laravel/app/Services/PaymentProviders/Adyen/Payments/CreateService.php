<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Adyen\Payments;

use App\Models\Payment;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\PaymentProviders\Adyen\Client;
use Illuminate\Http\Client\ConnectionException;
use App\Services\Invoices\Payments\ConnectionError;
use App\Services\PaymentProviders\Adyen\AdyenError;
use App\Services\PaymentProviders\Adyen\ValidationError;
use App\Services\PaymentProviders\Adyen\AuthenticationError;

/**
 * Port of Rails' PaymentProviders::Adyen::Payments::CreateService —
 * charges the invoice with the customer's stored card (POST /v70/payments,
 * Idempotency-Key "payment-{payment.id}", shopperInteraction ContAuth /
 * recurringProcessingModel UnscheduledCardOnFile).
 *
 * Ported semantics:
 *  - the stored payment method is re-resolved first (POST /v70/paymentMethods
 *    with merchantAccount + shopperReference) and written back onto the
 *    provider customer when found;
 *  - the `reference` is truncated to Adyen's 80-char limit keeping the TAIL
 *    (callers append the invoice number to the end — BIL-371);
 *  - payment.provider_payment_id = pspReference, payment.status = resultCode,
 *    payable_payment_status = provider.determine_payment_status;
 *  - an HTTP status over 400 (or AuthenticationError / ValidationError)
 *    fails the payment ("adyen_error" service failure); generic AdyenError
 *    also re-raises (reraise:); connection failures raise
 *    Invoices::Payments::ConnectionError for job-level retries.
 */
class CreateService extends BaseService
{
    /** Rails: REFERENCE_MAX_LENGTH (docs.adyen.com — POST /payments). */
    public const REFERENCE_MAX_LENGTH = 80;

    public function __construct(
        private readonly Payment $payment,
        private readonly string $reference,
        /** @var array<string, mixed> */
        private readonly array $metadata = [],
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('payment', 'error_message', 'error_code', 'reraise');

        $result->payment = $this->payment;

        $providerCustomer = $this->payment->paymentProviderCustomer;
        $paymentProvider = $providerCustomer?->paymentProvider;

        if ($providerCustomer === null || $paymentProvider === null) {
            return $result->serviceFailure(code: 'adyen_error', message: 'Missing payment provider customer');
        }

        $client = new Client(
            apiKey: (string) ($paymentProvider->apiKey() ?? ''),
            environment: $paymentProvider->adyenStyleEnvironment(),
            livePrefix: (string) ($paymentProvider->livePrefix() ?? ''),
        );

        try {
            $paymentMethodId = $this->updatePaymentMethodId($client, $paymentProvider, $providerCustomer);

            [$status, $response] = $client->call(
                'post',
                'payments',
                $this->paymentParams($providerCustomer, $paymentMethodId),
                ['idempotency-key' => 'payment-'.$this->payment->id],
            );

            if (Client::responseFailed($status)) {
                throw new AdyenError(
                    msg: (string) ($response['message'] ?? ''),
                    code: (string) ($response['errorType'] ?? ''),
                );
            }
        } catch (AuthenticationError|ValidationError $e) {
            return $this->prepareFailedResult($result, $e);
        } catch (AdyenError $e) {
            return $this->prepareFailedResult($result, $e, reraise: true);
        } catch (ConnectionException $e) {
            // Allow auto-retry with idempotency key.
            throw new ConnectionError($e);
        }

        $this->payment->provider_payment_id = $response['pspReference'] ?? null;
        $this->payment->status = (string) ($response['resultCode'] ?? '');
        $this->payment->payable_payment_status = $paymentProvider->determinePaymentStatus($this->payment->status);
        $this->payment->save();

        $result->payment = $this->payment;

        return $result;
    }

    /**
     * Rails: update_payment_method_id — POST /v70/paymentMethods with the
     * merchant account + shopper reference; the first stored payment method
     * is persisted onto the provider customer.
     *
     * @param  \App\Models\PaymentProviderCustomer  $providerCustomer
     */
    private function updatePaymentMethodId(Client $client, \App\Models\PaymentProvider $paymentProvider, $providerCustomer): ?string
    {
        [$status, $response] = $client->call('post', 'paymentMethods', [
            'merchantAccount' => $paymentProvider->merchantAccount(),
            'shopperReference' => $providerCustomer->provider_customer_id,
        ]);

        $paymentMethodId = $response['storedPaymentMethods'][0]['id'] ?? null;

        if ($paymentMethodId !== null) {
            $providerCustomer->pushToSettings('payment_method_id', $paymentMethodId);
            $providerCustomer->save();
        }

        return $this->payment->paymentMethod?->provider_method_id ?? $providerCustomer->legacyProviderMethodId();
    }

    /** Rails: payment_params. */
    private function paymentParams(\App\Models\PaymentProviderCustomer $providerCustomer, ?string $paymentMethodId): array
    {
        $customer = $providerCustomer->customer;
        $paymentProvider = $providerCustomer->paymentProvider;

        $params = [
            'amount' => [
                'currency' => mb_strtoupper($this->payment->amount_currency),
                'value' => (int) $this->payment->amount_cents,
            ],
            'reference' => $this->truncatedReference(),
            'paymentMethod' => [
                'type' => 'scheme',
                'storedPaymentMethodId' => $paymentMethodId,
            ],
            'shopperReference' => $providerCustomer->provider_customer_id,
            'merchantAccount' => $paymentProvider?->merchantAccount(),
            'shopperInteraction' => 'ContAuth',
            'recurringProcessingModel' => 'UnscheduledCardOnFile',
        ];

        if ($customer?->email !== null && $customer->email !== '') {
            $params['shopperEmail'] = $customer->email;
        }

        return $params;
    }

    /**
     * Rails: truncated_reference — when a long billing entity name pushes
     * past the limit the head is dropped and the tail kept (the invoice
     * number is what reconciliation needs — BIL-371).
     */
    private function truncatedReference(): string
    {
        $reference = $this->reference;

        if (mb_strlen($reference) <= self::REFERENCE_MAX_LENGTH) {
            return $reference;
        }

        return mb_substr($reference, -self::REFERENCE_MAX_LENGTH);
    }

    /** Rails: prepare_failed_result. */
    private function prepareFailedResult(BaseResult $result, AdyenError $error, bool $reraise = false): BaseResult
    {
        $result->error_message = $error->msg;
        $result->error_code = $error->code;
        $result->reraise = $reraise;

        $this->payment->status = 'failed';
        $this->payment->payable_payment_status = 'failed';
        $this->payment->save();

        return $result->serviceFailure(
            code: 'adyen_error',
            message: $error->code.': '.$error->msg,
            error: $error,
        );
    }
}
