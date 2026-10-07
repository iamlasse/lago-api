<?php

declare(strict_types=1);

namespace App\Jobs\Events;

use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\Events\PayInAdvanceService;

/**
 * Port of Rails' Events::PayInAdvanceJob (app/jobs/events/pay_in_advance_job.rb)
 * — bills a pay-in-advance event (one job per event, deduplicated on
 * organization / external_subscription_id / transaction_id).
 */
class PayInAdvanceJob implements ShouldQueue
{
    use \Illuminate\Foundation\Queue\Queueable;

    public function __construct(public readonly Event $event)
    {
        $this->onQueue(
            filter_var(env('SIDEKIQ_EVENTS'), FILTER_VALIDATE_BOOL) ? 'events' : 'default',
        );
    }

    /** Port of `unique :until_executed, on_conflict: :log`. */
    public function uniqueFor(): int
    {
        return 3600;
    }

    public function middleware(): array
    {
        return [new \App\Jobs\Middleware\UniqueJob];
    }

    /**
     * Rails: `lock_key_arguments` — [organization_id,
     * external_subscription_id, transaction_id]; the UniqueJob middleware
     * hashes the serialized job, which carries the same identity.
     */
    public function handle(): void
    {
        PayInAdvanceService::callBang(event: $this->event);
    }
}
