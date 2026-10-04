<?php

declare(strict_types=1);

namespace App\Jobs\PaymentProviders;

use App\Models\Organization;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Services\PaymentProviders\Stripe\HandleEventService;

/**
 * Port of Rails' PaymentProviders::Stripe::HandleEventJob (queue :providers)
 * — dispatches a verified webhook event to the event handlers (payment
 * status transitions, setup intents, ...).
 *
 * @param  array<string, mixed>  $event  the decoded Stripe event object
 */
class StripeHandleEventJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Organization $organization,
        public readonly array $event,
    ) {
        $this->onQueue(filter_var(env('SIDEKIQ_PROVIDERS'), FILTER_VALIDATE_BOOL) ? 'providers' : 'default');
    }

    public function handle(): void
    {
        HandleEventService::call(
            organization: $this->organization,
            eventJson: json_encode($this->event, JSON_THROW_ON_ERROR) ?: '{}',
        );
    }
}
