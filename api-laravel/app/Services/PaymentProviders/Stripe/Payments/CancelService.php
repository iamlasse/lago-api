<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Stripe\Payments;

use App\Models\Payment;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\Log;
use App\Services\PaymentProviders\Stripe\Client;
use App\Services\PaymentProviders\Stripe\InvalidRequestError;

/**
 * Port of Rails' PaymentProviders::Stripe::Payments::CancelService
 * (app/services/payment_providers/stripe/payments/cancel_service.rb) —
 * cancels a PaymentIntent with reason "abandoned"
 * (POST /v1/payment_intents/{id}/cancel) and follows the provider's
 * returned status onto the Payment.
 *
 * Best-effort cancel only for the "intent in a non-cancelable state" case
 * (payment_intent_unexpected_state: succeeded, processing, already
 * canceled, ...): log and treat as a successful no-op — the Payment record
 * is left untouched; the webhook for the prior state transition will land
 * its true state. Other InvalidRequestError codes (bad params, missing
 * resource, ...) propagate so the caller can retry or surface the failure.
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

        $client = new Client((string) $this->payment->paymentProvider->secretKey());

        try {
            // Rails: Stripe::PaymentIntent.cancel(payment.provider_payment_id,
            // {cancellation_reason: :abandoned}, {api_key: ...}).
            $stripeResult = $client->call(
                'post',
                '/v1/payment_intents/'.$this->payment->provider_payment_id.'/cancel',
                ['cancellation_reason' => 'abandoned'],
            );
        } catch (InvalidRequestError $e) {
            if ($e->code() !== 'payment_intent_unexpected_state') {
                throw $e;
            }

            Log::info("Stripe payment intent not cancelable for payment {$this->payment->id}: {$e->getMessage()}");

            return $result;
        }

        $this->payment->status = (string) ($stripeResult['status'] ?? '');
        $this->payment->payable_payment_status = $this->payment->paymentProvider->determinePaymentStatus($this->payment->status);
        $this->payment->save();

        $result->payment = $this->payment;

        return $result;
    }
}
