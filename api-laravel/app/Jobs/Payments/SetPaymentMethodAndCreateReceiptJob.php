<?php

declare(strict_types=1);

namespace App\Jobs\Payments;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use App\Jobs\PaymentReceipts\CreateJob;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Port of Rails' Payments::SetPaymentMethodAndCreateReceiptJob
 * (app/jobs/payments/set_payment_method_and_create_receipt_job.rb) — after
 * the provider settles the payment ("payment_intent.succeeded"): attach the
 * customer's payment method (by provider_method_id), then enqueue the
 * receipt creation when the organization issues receipts.
 *
 * TODO(port): Rails' Payments::SetPaymentMethodDataService (the provider
 * payment method data snapshot) — payments-slice seam, its absence only
 * skips the data payload.
 *
 * TODO(port): Rails' `unique :until_executed` + the Stripe rate-limit
 * retry_on (polynomial, 5 attempts).
 */
class SetPaymentMethodAndCreateReceiptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly Payment $payment,
        public readonly ?string $providerPaymentMethodId,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $payment = $this->payment->refresh();

        if ($payment === null) {
            return;
        }

        $this->setPaymentMethod($payment);

        // Now that the payment method is saved in the payment, we generate
        // the PaymentReceipt.
        if ($payment->customer?->organization?->issueReceiptsEnabled()) {
            dispatch(new \App\Jobs\PaymentReceipts\CreateJob($payment));
        }
    }

    private function setPaymentMethod(Payment $payment): void
    {
        if ($this->providerPaymentMethodId === null) {
            return;
        }

        $paymentMethod = $payment->customer?->paymentMethods()
            ->where('provider_method_id', $this->providerPaymentMethodId)
            ->first();

        if ($paymentMethod !== null) {
            $payment->payment_method_id = $paymentMethod->id;
            $payment->save();
        }
    }
}
