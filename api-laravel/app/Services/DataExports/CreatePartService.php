<?php

declare(strict_types=1);

namespace App\Services\DataExports;

use App\Models\DataExport;
use App\Models\DataExportPart;
use App\Services\BaseResult;
use App\Services\BaseService;
use Throwable;

/**
 * Port of Rails' DataExports::CreatePartService
 * (app/services/data_exports/create_part_service.rb): persists one export
 * batch (the object ids slice + its index).
 *
 * Rails' after_commit hook (ProcessPartJob.perform_later) is executed by
 * the caller — ExportResourcesService dispatches one ProcessPartJob per
 * created part right after its wrapping transaction commits, which is the
 * same observable order.
 */
class CreatePartService extends BaseService
{
    public function __construct(
        private readonly DataExport $dataExport,
        private readonly array $objectIds,
        private readonly int $index,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('data_export_part');

        try {
            $dataExportPart = $this->dataExport->dataExportParts()->create([
                'organization_id' => $this->dataExport->organization_id,
                'object_ids' => $this->objectIds,
                'index' => $this->index,
            ]);

            $result->data_export_part = $dataExportPart;
        } catch (Throwable $e) {
            $result->serviceFailure('data_export_part_creation_failed', $e->getMessage(), $e);
        }

        return $result;
    }
}
