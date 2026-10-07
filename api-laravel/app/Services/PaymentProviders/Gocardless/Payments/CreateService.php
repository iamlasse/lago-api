<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Gocardless\Payments;

use Throwable;
use App\Models\Payment;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Http\Client\ConnectionException;
use App\Services\Invoices\Payments\ConnectionError;
use App\Services\PaymentProviders\Gocardless\Client;
use App\Services\PaymentProviders\Gocardless\GoCardlessError;

/**
 * Port of Rails' PaymentProviders::Gocardless::Payments::CreateService —
 * creates a GoCardless payment against the customer's mandate
 * (POST /payments, Idempotency-Key "payment-{payment.id}",
 * retry_if_possible: false, metadata minus invoice_type, links[mandate]).
 *
 * Ported semantics:
 *  - the mandate comes from the payment's stored method
 *    (payment_method.provider_method_id) or is fetched from the mandates
 *    list (customer, statuses pending_customer_approval / pending_submission
 *    / submitted / active) and stored on the provider customer
 *    (provider_mandate_id); none found -> MandateNotFoundError;
 *  - payment.provider_payment_id / status follow the created payment,
 *    payable_payment_status the normalized status;
 *  - validation errors fail the payment ("gocardless_error"); mandate and
 *    generic API errors fail it AND re-raise (reraise:).
 */
class CreateService extends BaseService
{
    /** Rails: fetch_mandate_from_api status filter. */
    private const array MANDATE_STATUSES = ['pending_customer_approval', 'pending_submission', 'submitted', 'active'];

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
            return $result->serviceFailure(code: 'gocardless_error', message: 'Missing payment provider customer');
        }

        $client = new Client(
            accessToken: (string) ($paymentProvider->accessToken() ?? ''),
            environment: $paymentProvider->gocardlessEnvironment(),
        );

        try {
            $mandateId = $this->mandateId($client, $providerCustomer);

            [$status, $response] = $client->call('post', '/payments', [
                'amount' => (int) $this->payment->amount_cents,
                'currency' => mb_strtoupper($this->payment->amount_currency),
                'retry_if_possible' => false,
                'metadata' => $this->arrayExcept($this->metadata, 'invoice_type'),
                'links' => ['mandate' => $mandateId],
            ], ['Idempotency-Key' => 'payment-'.$this->payment->id]);

            if ($status >= 400) {
                throw new GoCardlessError(
                    $this->firstErrorMessage($response),
                    (string) ($response['error_type'] ?? $response['code'] ?? 'gocardless_error'),
                );
            }

            $gocardlessPayment = $response['payments'] ?? $response;
        } catch (MandateNotFoundError|GoCardlessError $e) {
            // Rails: MandateNotFoundError / GoCardlessError -> reraise: true.
            return $this->prepareFailedResult($result, $e, reraise: true);
        } catch (ConnectionException $e) {
            // GoCardless gem surfaces transport errors as raw Faraday
            // exceptions; wrap for the caller's retry path.
            throw new ConnectionError($e);
        }

        $this->payment->provider_payment_id = $gocardlessPayment['id'] ?? null;
        $this->payment->status = (string) ($gocardlessPayment['status'] ?? '');
        $this->payment->payable_payment_status = $paymentProvider->determinePaymentStatus($this->payment->status);
        $this->payment->save();

        $result->payment = $this->payment;

        return $result;
    }

    /** Rails: mandate_id — payment method first, then the mandates list. */
    private function mandateId(Client $client, \App\Models\PaymentProviderCustomer $providerCustomer): string
    {
        $mandateId = $this->payment->paymentMethod?->provider_method_id;

        if ($mandateId !== null) {
            return $mandateId;
        }

        [$status, $response] = $client->call('get', '/mandates?'.http_build_query([
            'customer' => $providerCustomer->provider_customer_id,
            'status' => self::MANDATE_STATUSES,
        ]));

        $mandate = $response['mandates'][0] ?? null;

        if ($mandate === null) {
            throw new MandateNotFoundError();
        }

        $providerCustomer->pushToSettings('provider_mandate_id', $mandate['id']);
        $providerCustomer->save();

        return (string) $mandate['id'];
    }

    /** Rails: prepare_failed_result. */
    private function prepareFailedResult(BaseResult $result, Throwable $error, bool $reraise = false): BaseResult
    {
        $result->error_message = $error->getMessage();
        $result->error_code = $error instanceof GoCardlessError ? $error->code : MandateNotFoundError::ERROR_CODE;
        $result->reraise = $reraise;

        $this->payment->status = 'failed';
        $this->payment->payable_payment_status = 'failed';
        $this->payment->save();

        return $result->serviceFailure(
            code: 'gocardless_error',
            message: ($error instanceof GoCardlessError ? $error->code : MandateNotFoundError::ERROR_CODE).': '.$error->getMessage(),
            error: $error,
        );
    }

    /** Rails: Hash#except. */
    private function arrayExcept(array $array, string ...$keys): array
    {
        foreach ($keys as $key) {
            unset($array[$key]);
        }

        return $array;
    }

    /** @param array<string, mixed> $response */
    private function firstErrorMessage(array $response): string
    {
        $error = $response['error'] ?? $response;

        if (is_array($error['message'] ?? null)) {
            $first = reset($error['message']);

            return is_array($first) ? (string) reset($first) : (string) $first;
        }

        return (string) ($error['message'] ?? 'GoCardless request failed');
    }
}
