<?php

declare(strict_types=1);

namespace App\Services\DataExports;

use App\Enums\DataExportFormat;
use App\Jobs\DataExports\ExportResourcesJob;
use App\Models\DataExport;
use App\Models\Organization;
use App\Models\User;
use App\Services\BaseResult;
use App\Services\BaseService;

/**
 * Port of Rails' DataExports::CreateService
 * (app/services/data_exports/create_service.rb): persists the export
 * request (format, resource type, filters) and kicks off the async
 * ExportResourcesJob.
 */
class CreateService extends BaseService
{
    public function __construct(
        private readonly Organization $organization,
        private readonly User $user,
        private readonly string $format,
        private readonly string $resourceType,
        private readonly ?array $resourceQuery = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('data_export');

        $membership = $this->user->memberships()
            ->where('organization_id', $this->organization->id)
            ->first();

        $formatIndex = DataExportFormat::fromOption($this->format);

        if ($membership === null || $formatIndex === null) {
            // Rails: create! would raise on the FK / enum validation; the
            // callers pass trusted wire data. membership null means the user
            // is not in the organization.
            return $result->notFoundFailure('membership');
        }

        $dataExport = DataExport::query()->create([
            'organization_id' => $this->organization->id,
            'membership_id' => $membership->id,
            'format' => $formatIndex,
            'resource_type' => $this->resourceType,
            'resource_query' => $this->resourceQuery ?? [],
        ]);

        ExportResourcesJob::dispatch($dataExport);

        // TODO(port): Utils::SecurityLog.produce (ClickHouse security logs) —
        //   log_type "export", log_event "export.created", resources
        //   {export_type:, resource_query:}.

        $result->data_export = $dataExport;

        return $result;
    }
}
