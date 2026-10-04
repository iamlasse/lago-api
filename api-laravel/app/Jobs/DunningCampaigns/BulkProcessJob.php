<?php

declare(strict_types=1);

namespace App\Jobs\DunningCampaigns;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\DunningCampaigns\BulkProcessService;

/**
 * Port of Rails' DunningCampaigns::BulkProcessJob
 * (app/jobs/dunning_campaigns/bulk_process_job.rb) —
 * DunningCampaigns::BulkProcessService.call.raise_if_error!, enqueued daily
 * by the Clock.
 */
class BulkProcessJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        BulkProcessService::call()->raiseIfError();
    }
}
