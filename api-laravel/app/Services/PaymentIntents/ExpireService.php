<?php

declare(strict_types=1);

namespace App\Services\PaymentIntents;

use App\Models\Invoice;
use App\Services\BaseResult;
use App\Models\PaymentIntent;
use App\Services\BaseService;

/**
 * Port of Rails' PaymentIntents::ExpireService — expires the active
 * hosted-checkout payment intents of an invoice.
 *
 * TODO(port): Invoices::Payments::PaymentProviders::Factory
 * :expire_payment_url — expires the open Stripe Checkout Session remotely
 * (POST /v1/checkout/sessions/{id}/expire) when the intent carries a
 * provider_session_id; the local record moves to expired regardless, which
 * also removes it from the reuse window (PaymentIntent.non_expired).
 */
class ExpireService extends BaseService
{
    public function __construct(
        private readonly Invoice $invoice,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('payment_intents');

        $paymentIntents = PaymentIntent::query()
            ->where('status', 0)
            ->where('invoice_id', $this->invoice->id)
            ->get();

        foreach ($paymentIntents as $paymentIntent) {
            // TODO(port): expire the open Stripe Checkout Session remotely
            // when payment_intent.provider_session_id is present.
            $paymentIntent->status = 1;
            $paymentIntent->expires_at = now();
            $paymentIntent->save();
        }

        $result->payment_intents = $paymentIntents;

        return $result;
    }
}
