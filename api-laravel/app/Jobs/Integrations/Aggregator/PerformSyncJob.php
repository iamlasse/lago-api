<?php

declare(strict_types=1);

namespace App\Jobs\Integrations\Aggregator;

use App\Models\Integration;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Services\Integrations\Aggregator\SyncService;

/**
 * Port of Rails' Integrations::Aggregator::PerformSyncJob
 * (app/jobs/integrations/aggregator/perform_sync_job.rb).
 *
 * TODO(port): the retry_on table (HttpError 3 attempts polynomial,
 * RequestLimitError 100 attempts) — the worker's $tries covers it until the
 * queue hardening slice; and the ItemsService leg (the Nango items sync —
 * `sync_items: true` is ignored here until that collector lands).
 */
class PerformSyncJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Integration $integration,
        public readonly bool $sync_items = true,
    ) {
        $this->onQueue('integrations');
    }

    public function handle(): void
    {
        SyncService::callBang(integration: $this->integration);
    }
}
