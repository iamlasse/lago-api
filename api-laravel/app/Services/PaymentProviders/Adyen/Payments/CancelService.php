<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Adyen\Payments;

use App\Models\Payment;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\Log;
use App\Services\PaymentProviders\Adyen\Client;
use Illuminate\Http\Client\ConnectionException;
use App\Services\Invoices\Payments\ConnectionError;
use App\Services\PaymentProviders\Adyen\AdyenError;
use App\Services\PaymentProviders\Adyen\ValidationError;

/**
 * Port of Rails' PaymentProviders::Adyen::Payments::CancelService
 * (app/services/payment_providers/adyen/payments/cancel_service.rb) —
 * ModificationApi cancel of an authorised payment
 * (POST /payments/{pspReference}/cancels, Idempotency-Key "payment-{id}").
 *
 * Adyen's sync cancel response is an acknowledgment ("received"), not a
 * final state — Adyen confirms the actual cancellation asynchronously via
 * the CANCELLATION webhook. The Payment record stays in its prior state
 * until that webhook lands.
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
            apiKey: (string) ($this->payment->paymentProvider->apiKey() ?? ''),
            environment: $this->payment->paymentProvider->adyenStyleEnvironment(),
            livePrefix: (string) ($this->payment->paymentProvider->livePrefix() ?? ''),
        );

        try {
            // Rails: client.checkout.modifications_api
            //   .cancel_authorised_payment_by_psp_reference(
            //     {merchantAccount: payment.payment_provider.merchant_account},
            //     payment.provider_payment_id,
            //     headers: {"Idempotency-Key" => "payment-#{payment.id}"})
            [$status, $response] = $client->call(
                'post',
                'payments/'.$this->payment->provider_payment_id.'/cancels',
                ['merchantAccount' => $this->payment->paymentProvider->merchantAccount()],
                ['Idempotency-Key' => 'payment-'.$this->payment->id],
            );

            if ($status === 422) {
                // Best-effort cancel only for the "modification cannot apply
                // to current state" case — most commonly "modification not
                // allowed on transaction status" when the payment is already
                // captured/cancelled or otherwise outside the cancellable
                // lifecycle window. Log and treat as a successful no-op so
                // the caller does not block on PSP-side cleanup.
                Log::info(sprintf(
                    'Adyen payment not cancelable for payment %s: status=%d message=%s',
                    $this->payment->id,
                    $status,
                    (string) ($response['message'] ?? ''),
                ));

                $result->payment = $this->payment;

                return $result;
            }

            if ($status >= 400) {
                throw new AdyenError(
                    msg: (string) ($response['message'] ?? ''),
                    code: (string) ($response['errorType'] ?? ''),
                );
            }
        } catch (ValidationError $e) {
            Log::info("Adyen payment not cancelable for payment {$this->payment->id}: {$e->msg}");

            $result->payment = $this->payment;

            return $result;
        } catch (ConnectionException $e) {
            throw new ConnectionError($e);
        }

        $result->payment = $this->payment;

        return $result;
    }
}
