<?php

declare(strict_types=1);

namespace App\Jobs\BillableMetricFilters;

use Illuminate\Bus\Queueable;
use App\Models\BillableMetric;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\BillableMetricFilters\DestroyAllService;

/**
 * Port of Rails' BillableMetricFilters::DestroyAllJob
 * (app/jobs/billable_metric_filters/destroy_all_job.rb).
 */
class DestroyAllJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly string $billableMetricId)
    {
        $this->onQueue('low');
    }

    public function handle(): void
    {
        $billableMetric = BillableMetric::withTrashed()->find($this->billableMetricId);

        if ($billableMetric === null) {
            return;
        }

        DestroyAllService::callBang(billableMetric: $billableMetric);
    }
}
