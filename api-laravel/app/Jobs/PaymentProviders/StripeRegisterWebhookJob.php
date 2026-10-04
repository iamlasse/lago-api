<?php

declare(strict_types=1);

namespace App\Jobs\PaymentProviders;

use App\Models\PaymentProvider;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Services\PaymentProviders\Stripe\RegisterWebhookService;

/**
 * Port of Rails' PaymentProviders::Stripe::RegisterWebhookJob (queue
 * :providers) — provisions the Stripe webhook endpoint after a provider
 * connects.
 */
class StripeRegisterWebhookJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly PaymentProvider $paymentProvider,
    ) {
        $this->onQueue(filter_var(env('SIDEKIQ_PROVIDERS'), FILTER_VALIDATE_BOOL) ? 'providers' : 'default');
    }

    public function handle(): void
    {
        RegisterWebhookService::callBang(paymentProvider: $this->paymentProvider);
    }
}
