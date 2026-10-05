<?php

declare(strict_types=1);

namespace App\Jobs\PaymentProviders;

use App\Models\Organization;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Services\PaymentProviders\Adyen\HandleEventService;

/**
 * Port of Rails' PaymentProviders::Adyen::HandleEventJob (queue :providers)
 * — dispatches a verified notification item to the event handlers.
 *
 * @param  array<string, mixed>  $event  the NotificationRequestItem
 */
class AdyenHandleEventJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Organization $organization,
        public readonly string $eventJson,
    ) {
        $this->onQueue(filter_var(env('SIDEKIQ_PROVIDERS'), FILTER_VALIDATE_BOOL) ? 'providers' : 'default');
    }

    public function handle(): void
    {
        HandleEventService::call(
            organization: $this->organization,
            eventJson: $this->eventJson,
        );
    }
}
