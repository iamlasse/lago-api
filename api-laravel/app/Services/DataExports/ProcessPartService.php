<?php

declare(strict_types=1);

namespace App\Services\DataExports;

use App\Models\DataExportPart;
use App\Services\BaseResult;
use App\Services\BaseService;

/**
 * Port of Rails' DataExports::ProcessPartService
 * (app/services/data_exports/process_part_service.rb): serializes one
 * part's objects into its CSV lines, marks the part completed, and — when
 * it was the last one — queues the combine.
 */
class ProcessPartService extends BaseService
{
    public function __construct(
        private readonly DataExportPart $dataExportPart,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('data_export_part');

        $dataExport = $this->dataExportPart->dataExport;

        $result->data_export_part = $this->dataExportPart;

        if ($this->dataExportPart->completed) {
            return $result;
        }

        $exportClass = $dataExport->exportClass();

        if ($exportClass === null) {
            return $result->serviceFailure(
                $this->dataExportPart->dataExport->resource_type.' resource not supported',
                "'{$this->dataExportPart->dataExport->resource_type}' resource not supported",
            );
        }

        // Rails: the CSV service writes into a Tempfile, the part stores the
        // file's contents; the port returns the CSV string directly.
        $csv = $exportClass::call(dataExportPart: $this->dataExportPart)->raiseIfError()->csv;

        $this->dataExportPart->csv_lines = $csv;
        $this->dataExportPart->completed = true;
        $this->dataExportPart->save();

        // check if we are the last one to finish
        if ($this->lastCompleted()) {
            CombinePartsJob::dispatch($this->dataExportPart->dataExport);
        }

        return $result;
    }

    private function lastCompleted(): bool
    {
        $dataExport = $this->dataExportPart->dataExport;

        return $dataExport->dataExportParts()->completed()->count() === $dataExport->dataExportParts()->count();
    }
}
