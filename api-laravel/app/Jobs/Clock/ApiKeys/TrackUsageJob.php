<?php

declare(strict_types=1);

namespace App\Jobs\Clock\ApiKeys;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use App\Services\ApiKeys\TrackUsageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Port of Rails' Clock::ApiKeys::TrackUsageJob
 * (app/jobs/clock/api_keys/track_usage_job.rb) — the hourly flush of the
 * api_key_last_used_<id> cache entries onto the api_keys rows.
 *
 * Rails does NOT mark this job unique (the flush is idempotent).
 */
class TrackUsageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct()
    {
        $this->onQueue('clock');
    }

    public function handle(): void
    {
        TrackUsageService::call();
    }
}
