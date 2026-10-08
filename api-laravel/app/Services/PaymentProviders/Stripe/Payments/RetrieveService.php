<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Stripe\Payments;

use App\Models\Payment;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\PaymentProviders\Stripe\Client;

/**
 * Port of Rails' PaymentProviders::Stripe::Payments::RetrieveService
 * (app/services/payment_providers/stripe/payments/retrieve_service.rb) —
 * reads the live PaymentIntent (GET /v1/payment_intents/{id} with
 * expand[]=payment_method) so the abandoned-payment recovery can branch on
 * the provider's truth instead of our possibly-stale row.
 *
 * Like Rails, no rescue here: the Stripe error taxonomy raised by the
 * client (authentication/permission/invalid_request/rate_limit) is the
 * caller's (Invoices::Payments::CancelAbandonedService) decision.
 */
class RetrieveService extends BaseService
{
    public function __construct(private readonly Payment $payment)
    {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('status', 'payment_method_type');

        // Rails: Stripe::PaymentIntent.retrieve({id:, expand: ["payment_method"]},
        // {api_key: payment.payment_provider.secret_key}).
        $client = new Client((string) $this->payment->paymentProvider->secretKey());

        $intent = $client->call(
            'get',
            '/v1/payment_intents/'.$this->payment->provider_payment_id,
            ['expand' => ['payment_method']],
        );

        $result->status = (string) ($intent['status'] ?? '');
        $result->payment_method_type = $intent['payment_method']['type'] ?? null;

        return $result;
    }
}
