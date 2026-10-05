<?php

declare(strict_types=1);

namespace App\Jobs\PaymentProviders;

use App\Models\Organization;
use App\Models\PaymentProvider;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Services\PaymentProviders\Gocardless\HandleEventService;

/**
 * Port of Rails' PaymentProviders::Gocardless::HandleEventJob (queue
 * :providers) — dispatches one parsed webhook event to the event handlers.
 */
class GocardlessHandleEventJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Organization $organization,
        public readonly PaymentProvider $paymentProvider,
        public readonly string $eventJson,
    ) {
        $this->onQueue(filter_var(env('SIDEKIQ_PROVIDERS'), FILTER_VALIDATE_BOOL) ? 'providers' : 'default');
    }

    public function handle(): void
    {
        HandleEventService::call(
            paymentProvider: $this->paymentProvider,
            eventJson: $this->eventJson,
        );
    }
}
