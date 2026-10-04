<?php

declare(strict_types=1);

namespace App\Jobs\PaymentProviders;

use App\Models\PaymentProvider;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Services\PaymentProviders\Stripe\ExpirePaymentIntentsService;

/**
 * Port of Rails' PaymentProviders::Stripe::ExpirePaymentIntentsJob (queue
 * :providers) — expires the open hosted-checkout payment intents of the
 * provider's customers when the consent setting changes (outstanding
 * checkout links must be rebuilt).
 */
class StripeExpirePaymentIntentsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly PaymentProvider $paymentProvider,
    ) {
        $this->onQueue(filter_var(env('SIDEKIQ_PROVIDERS'), FILTER_VALIDATE_BOOL) ? 'providers' : 'default');
    }

    public function handle(): void
    {
        ExpirePaymentIntentsService::call(paymentProvider: $this->paymentProvider);
    }
}
