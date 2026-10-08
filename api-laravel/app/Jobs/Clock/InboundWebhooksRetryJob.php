<?php

declare(strict_types=1);

namespace App\Jobs\Clock;

use Illuminate\Bus\Queueable;
use App\Models\InboundWebhook;
use App\Jobs\Middleware\UniqueJob;
use App\Jobs\InboundWebhooks\ProcessJob;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Port of Rails' Clock::InboundWebhooksRetryJob
 * (app/jobs/clock/inbound_webhooks_retry_job.rb) — every 15 minutes, re-feed
 * inbound webhooks that fell out of the processing window: "processing"
 * rows past the 2h mark (lost worker) and old "pending" rows that were
 * never picked up.
 */
class InboundWebhooksRetryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct()
    {
        $this->onQueue('clock');
    }

    /** Port of `unique :until_executed, on_conflict: :log, lock_ttl: 4.hours`. */
    public function uniqueFor(): int
    {
        return 4 * 3600;
    }

    public function middleware(): array
    {
        return [new UniqueJob];
    }

    public function handle(): void
    {
        InboundWebhook::query()
            ->retriable()
            ->chunkById(1000, function ($inboundWebhooks): void {
                foreach ($inboundWebhooks as $inboundWebhook) {
                    ProcessJob::dispatch($inboundWebhook);
                }
            });
    }
}
