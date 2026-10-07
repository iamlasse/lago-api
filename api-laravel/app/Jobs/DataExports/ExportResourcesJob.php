<?php

declare(strict_types=1);

namespace App\Jobs\DataExports;

use App\Models\DataExport;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use App\Services\DataExports\ExportResourcesService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Port of Rails' DataExports::ExportResourcesJob
 * (app/jobs/data_exports/export_resources_job.rb).
 */
class ExportResourcesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Rails: DEFAULT_BATCH_SIZE = 20. */
    public const int DEFAULT_BATCH_SIZE = 20;

    public function __construct(
        public readonly DataExport $dataExport,
        public readonly int $batchSize = self::DEFAULT_BATCH_SIZE,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        ExportResourcesService::call(
            dataExport: $this->dataExport,
            batchSize: $this->batchSize,
        )->raiseIfError();
    }
}
