<?php

declare(strict_types=1);

namespace App\Jobs\Clock;

use App\Models\InboundWebhook;
use Illuminate\Bus\Queueable;
use App\Jobs\Middleware\UniqueJob;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Port of Rails' Clock::InboundWebhooksCleanupJob
 * (app/jobs/clock/inbound_webhooks_cleanup_job.rb) — the daily purge of
 * inbound webhooks older than 90 days.
 */
class InboundWebhooksCleanupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct()
    {
        $this->onQueue('clock');
    }

    /** Port of `unique :until_executed, on_conflict: :log`. */
    public function uniqueFor(): int
    {
        return 20 * 3600;
    }

    public function middleware(): array
    {
        return [new UniqueJob];
    }

    public function handle(): void
    {
        InboundWebhook::query()
            ->where('updated_at', '<', now()->subDays(90))
            ->chunkById(1000, fn ($inboundWebhooks) => $inboundWebhooks->each(
                fn (InboundWebhook $inboundWebhook) => $inboundWebhook->delete(),
            ));
    }
}
