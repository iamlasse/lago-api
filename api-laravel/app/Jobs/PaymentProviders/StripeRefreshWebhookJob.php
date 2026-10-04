<?php

declare(strict_types=1);

namespace App\Jobs\PaymentProviders;

use App\Models\PaymentProvider;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Services\PaymentProviders\Stripe\RefreshWebhookService;

/**
 * Port of Rails' PaymentProviders::Stripe::RefreshWebhookJob (queue
 * :providers) — re-points the managed Stripe webhook endpoint after the
 * provider code changed.
 */
class StripeRefreshWebhookJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly PaymentProvider $paymentProvider,
    ) {
        $this->onQueue(filter_var(env('SIDEKIQ_PROVIDERS'), FILTER_VALIDATE_BOOL) ? 'providers' : 'default');
    }

    public function handle(): void
    {
        RefreshWebhookService::callBang(paymentProvider: $this->paymentProvider);
    }
}
