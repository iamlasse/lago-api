<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Gocardless\Payments;

use App\Models\Payment;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\ConnectionException;
use App\Services\Invoices\Payments\ConnectionError;
use App\Services\PaymentProviders\Gocardless\Client;
use App\Services\PaymentProviders\Gocardless\GoCardlessError;

/**
 * Port of Rails' PaymentProviders::Gocardless::Payments::CancelService
 * (app/services/payment_providers/gocardless/payments/cancel_service.rb) —
 * cancels a GoCardless payment
 * (POST /payments/{id}/actions/cancel) and follows the returned status
 * onto the Payment.
 *
 * Best-effort cancel only for the documented "cancellation_failed" case —
 * the payment is in a state that cannot be cancelled (already submitted,
 * paid out, cancelled, ...). Log and treat as a successful no-op; the
 * Payment record is left untouched. Other error codes propagate so the
 * caller can retry or surface the failure.
 */
class CancelService extends BaseService
{
    public function __construct(private readonly Payment $payment)
    {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('payment');

        $client = new Client(
            accessToken: (string) ($this->payment->paymentProvider->accessToken() ?? ''),
            environment: $this->payment->paymentProvider->gocardlessEnvironment(),
        );

        try {
            // Rails: client.payments.cancel(payment.provider_payment_id).
            [$status, $response] = $client->call(
                'post',
                '/payments/'.$this->payment->provider_payment_id.'/actions/cancel',
            );
        } catch (GoCardlessError $e) {
            // The REST client surfaces transport failures as GoCardlessError;
            // wrap so the caller can retry through the same path as other
            // PSPs (matches the create-side error handling pattern).
            throw new ConnectionError($e);
        } catch (ConnectionException $e) {
            throw new ConnectionError($e);
        }

        if ($status >= 400) {
            // GoCardless error envelope: {error: {type, code, message, ...}}.
            $error = is_array($response['error'] ?? null) ? $response['error'] : [];
            $code = (string) ($error['code'] ?? $response['code'] ?? '');

            // Port of the InvalidStateError "cancellation_failed" rescue.
            if ($code === 'cancellation_failed') {
                Log::info("GoCardless payment not cancelable for payment {$this->payment->id}: cancellation_failed");

                $result->payment = $this->payment;

                return $result;
            }

            throw new GoCardlessError(
                (string) ($error['message'] ?? $response['message'] ?? 'GoCardless cancel failed'),
                $code !== '' ? $code : 'gocardless_error',
            );
        }

        $cancelled = $response['payments'] ?? $response;

        $this->payment->status = (string) ($cancelled['status'] ?? '');
        $this->payment->payable_payment_status = $this->payment->paymentProvider->determinePaymentStatus($this->payment->status);
        $this->payment->save();

        $result->payment = $this->payment;

        return $result;
    }
}
