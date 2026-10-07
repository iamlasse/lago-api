<?php

declare(strict_types=1);

namespace App\Jobs\DataExports;

use Illuminate\Bus\Queueable;
use App\Models\DataExportPart;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\DataExports\ProcessPartService;

/**
 * Port of Rails' DataExports::ProcessPartJob
 * (app/jobs/data_exports/process_part_job.rb).
 */
class ProcessPartJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly DataExportPart $dataExportPart,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        ProcessPartService::call(dataExportPart: $this->dataExportPart)->raiseIfError();
    }
}
