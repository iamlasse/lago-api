<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\DataExport;
use App\Models\DataExportPart;
use Illuminate\Support\Facades\Queue;
use App\Jobs\DataExports\ProcessPartJob;
use App\Jobs\DataExports\CombinePartsJob;
use App\Jobs\DataExports\ExportResourcesJob;
use App\Services\DataExports\ProcessPartService;

uses()->group('ledger:job:DataExports.ExportResourcesJob', 'ledger:job:DataExports.ProcessPartJob', 'ledger:job:DataExports.CombinePartsJob');

/**
 * Ports of Rails' spec/jobs/data_exports/{export_resources_job,
 * process_part_job, combine_parts_job}_spec.rb — each job delegates to its
 * service with its arguments.
 */
function jobsExportOrg(): object
{
    return App\Models\Organization::factory()->create();
}

it('export resources job runs the export', function (): void {
    $organization = jobsExportOrg();
    $dataExport = DataExport::factory()->forOrganization($organization)->create();
    Invoice::factory()->create(['organization_id' => $organization->id]);

    (new ExportResourcesJob($dataExport))->handle();

    expect($dataExport->refresh()->status)->toBe(1) // processing
        ->and($dataExport->dataExportParts()->count())->toBe(1);
});

it('process part job serializes the part', function (): void {
    $organization = jobsExportOrg();
    $dataExport = DataExport::factory()->forOrganization($organization)->create();
    $invoice = Invoice::factory()->create(['organization_id' => $organization->id]);
    $part = DataExportPart::factory()->forDataExport($dataExport)->create([
        'object_ids' => [$invoice->id],
    ]);

    (new ProcessPartJob($part))->handle();

    expect($part->refresh()->completed)->toBeTrue()
        ->and($part->csv_lines)->toContain($invoice->id);
});

it('combine parts job attaches the file and completes the export', function (): void {
    Queue::fake();
    Mail::fake();

    $organization = jobsExportOrg();
    $dataExport = DataExport::factory()->forOrganization($organization)->create();
    $invoice = Invoice::factory()->create(['organization_id' => $organization->id]);
    $part = DataExportPart::factory()->forDataExport($dataExport)->create([
        'object_ids' => [$invoice->id],
    ]);

    ProcessPartService::call(dataExportPart: $part);

    (new CombinePartsJob($dataExport))->handle();

    expect($dataExport->refresh()->isCompleted())->toBeTrue()
        ->and(App\Support\ActiveStorage::blob($dataExport, 'file'))->not->toBeNull();
});
