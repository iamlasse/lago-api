<?php

declare(strict_types=1);

namespace App\Jobs\DataExports;

use App\Models\DataExport;
use Illuminate\Bus\Queueable;
use App\Jobs\Middleware\UniqueJob;
use Illuminate\Queue\InteractsWithQueue;
use App\Services\DataExports\CombinePartsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Port of Rails' DataExports::CombinePartsJob
 * (app/jobs/data_exports/combine_parts_job.rb) — unique until executed, so
 * the last-finished part's dispatch can't double-combine.
 */
class CombinePartsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly DataExport $dataExport,
    ) {
        $this->onQueue('default');
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
        CombinePartsService::call(dataExport: $this->dataExport)->raiseIfError();
    }
}
