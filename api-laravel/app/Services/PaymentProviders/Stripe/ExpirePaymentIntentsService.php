<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Stripe;

use App\Services\BaseResult;
use App\Models\PaymentIntent;
use App\Services\BaseService;
use App\Models\PaymentProvider;

/**
 * Port of Rails' PaymentProviders::Stripe::ExpirePaymentIntentsService —
 * expires every active hosted-checkout payment intent (the payment_url
 * records, not Stripe's PaymentIntent objects) of the provider's customers.
 *
 * TODO(port): Invoices::Payments::PaymentProviders::Factory
 * :expire_payment_url — expires the open Stripe Checkout Session
 * (POST /v1/checkout/sessions/{id}/expire) once the payment-url slice
 * lands; the local record still moves to expired below.
 */
class ExpirePaymentIntentsService extends BaseService
{
    public function __construct(
        private readonly PaymentProvider $paymentProvider,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult();

        // Join-based scoping (mirrors Rails' has_many :customers, through:
        // :payment_provider_customers); nested whereIn-closure subqueries
        // proved unreliable here.
        $paymentIntents = PaymentIntent::query()
            ->from('payment_intents as pi')
            ->join('invoices as i', 'i.id', '=', 'pi.invoice_id')
            ->join('customers as c', 'c.id', '=', 'i.customer_id')
            ->join('payment_provider_customers as ppc', function ($join): void {
                $join->on('ppc.customer_id', '=', 'c.id')->whereNull('ppc.deleted_at');
            })
            ->where('pi.status', 0)
            ->where('ppc.payment_provider_id', $this->paymentProvider->id)
            // pi.* only: a bare get() would also pull the joined tables id
            // columns and overwrite the intent id on the hydrated model.
            ->get(['pi.*']);

        foreach ($paymentIntents as $paymentIntent) {
            // TODO(port): expire the open Stripe Checkout Session remotely
            // when the intent carries a provider_session_id.
            $paymentIntent->status = 1;
            $paymentIntent->expires_at = now();
            $paymentIntent->save();
        }

        return $result;
    }
}
