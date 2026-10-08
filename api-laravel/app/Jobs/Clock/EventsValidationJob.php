<?php

declare(strict_types=1);

namespace App\Jobs\Clock;

use Illuminate\Bus\Queueable;
use App\Models\Events\LastHourMv;
use App\Jobs\Middleware\UniqueJob;
use Illuminate\Support\Facades\DB;
use App\Jobs\Events\PostValidationJob;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Port of Rails' Clock::EventsValidationJob
 * (app/jobs/clock/events_validation_job.rb) — the hourly
 * `schedule:post_validate_events` clock entry (clock.rb, at: "*:05",
 * gated on LAGO_DISABLE_EVENTS_VALIDATION).
 *
 * It refreshes the `last_hour_events_mv` materialized view (Rails refreshes
 * it through Scenic; the view lives in the frozen Postgres schema, so the
 * port issues the REFRESH directly), then enqueues one
 * Events\PostValidationJob per organization that had events in the last
 * hour and owns at least one webhook endpoint.
 */
class EventsValidationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct()
    {
        $this->onQueue('clock');
    }

    /** Port of `unique :until_executed`. */
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
        // NOTE: refresh the last hour events materialized view
        DB::statement('REFRESH MATERIALIZED VIEW last_hour_events_mv');

        $organizationIds = LastHourMv::distinctOrganizationIds();

        foreach ($organizationIds as $organizationId) {
            $organization = \App\Models\Organization::find($organizationId);

            if ($organization === null) {
                continue;
            }

            if (! $organization->webhookEndpoints()->exists()) {
                continue;
            }

            dispatch(new PostValidationJob($organization));
        }
    }
}
