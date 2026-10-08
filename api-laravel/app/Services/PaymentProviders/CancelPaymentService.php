<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders;

use App\Models\Payment;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\Log;
use App\Services\PaymentProviders\Adyen\Payments\CancelService as AdyenCancelService;
use App\Services\PaymentProviders\Stripe\Payments\CancelService as StripeCancelService;
use App\Services\PaymentProviders\Gocardless\Payments\CancelService as GocardlessCancelService;

/**
 * Port of Rails' PaymentProviders::CancelPaymentService
 * (app/services/payment_providers/cancel_payment_service.rb) — provider-
 * agnostic cancel dispatch: routes to the PSP's dedicated cancel service,
 * or logs-and-skips for providers without one.
 *
 * Cashfree, Flutterwave, MoneyHash, and any future provider without a
 * dedicated cancel service: nothing to do here. The eventual webhook (or
 * reconciliation) is the lifecycle authority for the Payment.
 */
class CancelPaymentService extends BaseService
{
    public function __construct(private readonly Payment $payment)
    {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('payment');

        $result->payment = $this->payment;

        if ($this->payment->paymentProvider === null) {
            return $result;
        }

        if ($this->payment->provider_payment_id === null || $this->payment->provider_payment_id === '') {
            return $result;
        }

        // Rails: payment.succeeded? — the payable_payment_status enum predicate.
        if ($this->payment->payablePaymentStatus() === 'succeeded') {
            return $result;
        }

        match ($this->payment->paymentProvider->type) {
            'PaymentProviders::StripeProvider' => StripeCancelService::callBang(payment: $this->payment),
            'PaymentProviders::AdyenProvider' => AdyenCancelService::callBang(payment: $this->payment),
            'PaymentProviders::GocardlessProvider' => GocardlessCancelService::callBang(payment: $this->payment),
            default => Log::info(sprintf(
                'PaymentProviders::CancelPaymentService: PSP cancel not supported for %s (payment %s); skipping',
                (string) $this->payment->paymentProvider->type,
                $this->payment->id,
            )),
        };

        return $result;
    }
}
