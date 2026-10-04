<?php

declare(strict_types=1);

namespace App\Jobs\Clock;

use Illuminate\Bus\Queueable;
use App\Jobs\Middleware\UniqueJob;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Jobs\DunningCampaigns\BulkProcessJob;

/**
 * Port of Rails' Clock::ProcessDunningCampaignsJob
 * (app/jobs/clock/process_dunning_campaigns_job.rb — daily) — the dunning
 * loop's entry point, fanning out to BulkProcessJob.
 */
class ProcessDunningCampaignsJob implements ShouldQueue
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
        BulkProcessJob::dispatch();
    }
}
