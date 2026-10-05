<?php

declare(strict_types=1);

namespace App\Jobs\Clock;

use App\Models\LifetimeUsage;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Jobs\LifetimeUsages\RecalculateAndCheckJob;

/**
 * Port of Rails' Clock::RefreshLifetimeUsagesJob
 * (app/jobs/clock/refresh_lifetime_usages_job.rb) — every
 * LAGO_LIFETIME_USAGE_REFRESH_INTERVAL_SECONDS (default 5 minutes), fans out
 * one RecalculateAndCheckJob per lifetime usage flagged for an invoiced-usage
 * recalculation.
 */
class RefreshLifetimeUsagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct()
    {
        $this->onQueue('clock');
    }

    /** Port of `unique :until_executed, on_conflict: :log`. */
    public function uniqueFor(): int
    {
        return 4 * 3600;
    }

    public function middleware(): array
    {
        return [new \App\Jobs\Middleware\UniqueJob];
    }

    public function handle(): void
    {
        if (! \App\Support\License::premium()) {
            return;
        }

        LifetimeUsage::query()
            ->where('recalculate_invoiced_usage', true)
            // Rails: Organization.with_progressive_billing_support
            //   .or(Organization.with_lifetime_usage_support).
            ->whereHas('organization', function ($query): void {
                $query->where(function ($q): void {
                    $q->whereRaw('? = ANY(premium_integrations)', ['progressive_billing'])
                        ->orWhereRaw('? = ANY(premium_integrations)', ['lifetime_usage']);
                });
            })
            ->chunkById(500, function ($lifetimeUsages): void {
                foreach ($lifetimeUsages as $lifetimeUsage) {
                    RecalculateAndCheckJob::dispatch($lifetimeUsage);
                }
            });
    }
}
