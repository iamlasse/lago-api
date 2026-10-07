<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Invoice;
use App\Models\DataExport;
use App\Models\DataExportPart;
use App\Enums\DataExportStatus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use App\Jobs\DataExports\ProcessPartJob;
use App\Jobs\DataExports\CombinePartsJob;
use App\Services\DataExports\CreateService;
use App\Jobs\DataExports\ExportResourcesJob;
use App\Services\DataExports\CreatePartService;
use App\Services\DataExports\ProcessPartService;
use App\Services\DataExports\CombinePartsService;
use App\Services\DataExports\ExportResourcesService;

uses()->group('ledger:svc:DataExports');

/**
 * Ports of Rails' spec/services/data_exports/{create_service,
 * create_part_service, export_resources_service, process_part_service,
 * combine_parts_service}_spec.rb.
 */
function dataExportOrg(): object
{
    return App\Models\Organization::factory()->create();
}

function dataExportUser(object $organization): User
{
    $user = User::factory()->create(['email' => 'export@example.com']);
    App\Models\Membership::query()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'status' => 0,
    ]);

    return $user;
}

it('creates a data export and queues the export job', function (): void {
    Queue::fake();

    $organization = dataExportOrg();
    $user = dataExportUser($organization);

    $result = CreateService::call(
        organization: $organization,
        user: $user,
        format: 'csv',
        resourceType: 'invoices',
        resourceQuery: ['search_term' => 'INV'],
    );

    expect($result->failure())->toBeFalse();

    $dataExport = $result->data_export;

    expect($dataExport->organization_id)->toBe($organization->id)
        ->and($dataExport->membership_id)->not->toBeNull()
        ->and($dataExport->format)->toBe(0) // csv
        ->and($dataExport->resource_type)->toBe('invoices')
        ->and($dataExport->resource_query)->toBe(['search_term' => 'INV'])
        ->and($dataExport->status)->toBe(0); // pending

    Queue::assertPushed(ExportResourcesJob::class, fn (ExportResourcesJob $job) => $job->dataExport->id === $dataExport->id);
});

it('creates an export part with the batched ids', function (): void {
    Queue::fake();

    $dataExport = DataExport::factory()->forOrganization(dataExportOrg())->create();

    $result = CreatePartService::call(
        dataExport: $dataExport,
        objectIds: ['11111111-1111-1111-1111-111111111111'],
        index: 3,
    );

    expect($result->failure())->toBeFalse();

    $part = $result->data_export_part;

    expect($part->data_export_id)->toBe($dataExport->id)
        ->and($part->organization_id)->toBe($dataExport->organization_id)
        ->and($part->object_ids)->toBe(['11111111-1111-1111-1111-111111111111'])
        ->and($part->index)->toBe(3)
        ->and($part->completed)->toBeFalse();
});

it('exports resources into parts and marks the export processing', function (): void {
    Queue::fake();

    $organization = dataExportOrg();
    $dataExport = DataExport::factory()->forOrganization($organization)->create();

    $invoice = Invoice::factory()->create(['organization_id' => $organization->id]);

    $result = ExportResourcesService::call(dataExport: $dataExport, batchSize: 100);

    expect($result->failure())->toBeFalse()
        ->and($dataExport->refresh()->status)->toBe(DataExportStatus::Processing->value);

    $parts = $dataExport->dataExportParts()->get();

    expect($parts)->toHaveCount(1)
        ->and($parts->first()->object_ids)->toBe([$invoice->id]);

    Queue::assertPushed(ProcessPartJob::class, 1);
});

it('splits many invoices into several parts', function (): void {
    Queue::fake();

    $organization = dataExportOrg();
    $dataExport = DataExport::factory()->forOrganization($organization)->create();

    for ($i = 5; $i >= 1; $i--) {
        // Created oldest-first so the query order (issuing date ASC) splits
        // like Rails' InvoicesQuery.
        Invoice::factory()->create([
            'organization_id' => $organization->id,
            'issuing_date' => now()->subDays($i)->toDateString(),
        ]);
    }

    $result = ExportResourcesService::call(dataExport: $dataExport, batchSize: 2);

    expect($result->failure())->toBeFalse();

    $parts = $dataExport->dataExportParts()->orderBy('index')->get();

    expect($parts)->toHaveCount(3)
        ->and($parts[0]->object_ids)->toHaveCount(2)
        ->and($parts[1]->object_ids)->toHaveCount(2)
        ->and($parts[2]->object_ids)->toHaveCount(1);
});

it('fails on an expired export', function (): void {
    Queue::fake();

    $dataExport = DataExport::factory()->forOrganization(dataExportOrg())->create([
        'expires_at' => now()->subHour(),
    ]);

    $result = ExportResourcesService::call(dataExport: $dataExport);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->code)->toBe('data_export_expired');
});

it('fails on an already processed export', function (): void {
    Queue::fake();

    $dataExport = DataExport::factory()->forOrganization(dataExportOrg())->completed()->create();

    $result = ExportResourcesService::call(dataExport: $dataExport);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->code)->toBe('data_export_processed');
});

it('processes a part into its csv lines', function (): void {
    Queue::fake();
    Http::fake(['*' => Http::response('')]);

    $organization = dataExportOrg();
    $dataExport = DataExport::factory()->forOrganization($organization)->create();

    $invoice = Invoice::factory()->create(['organization_id' => $organization->id]);
    $part = DataExportPart::factory()->forDataExport($dataExport)->create([
        'object_ids' => [$invoice->id],
    ]);

    $result = ProcessPartService::call(dataExportPart: $part);

    expect($result->failure())->toBeFalse()
        ->and($part->refresh()->completed)->toBeTrue();

    // The part stores the DATA rows only — headers are written by
    // CombinePartsService (Rails' behaviour).
    $lines = explode("\n", mb_trim((string) $part->csv_lines));

    expect(count($lines))->toBe(1)
        ->and(str_contains($lines[0], $invoice->id))->toBeTrue();

    // The last part queues the combine.
    Queue::assertPushed(CombinePartsJob::class, fn (CombinePartsJob $job) => $job->dataExport->id === $dataExport->id);
});

it('does not reprocess a completed part', function (): void {
    Queue::fake();

    $dataExport = DataExport::factory()->forOrganization(dataExportOrg())->create();
    $part = DataExportPart::factory()->forDataExport($dataExport)->completed()->create([
        'csv_lines' => 'already,here',
    ]);

    $result = ProcessPartService::call(dataExportPart: $part);

    expect($result->failure())->toBeFalse()
        ->and($part->csv_lines)->toBe('already,here');

    Queue::assertNotPushed(CombinePartsJob::class);
});

it('combines the parts into one attachment, marks completed and emails the member', function (): void {
    Queue::fake();
    Mail::fake();

    $organization = dataExportOrg();
    $user = dataExportUser($organization);
    $dataExport = DataExport::factory()->forOrganization($organization)->forMembership(
        $user->memberships->first(),
    )->create();

    $invoice = Invoice::factory()->create(['organization_id' => $organization->id]);
    $part = DataExportPart::factory()->forDataExport($dataExport)->create([
        'object_ids' => [$invoice->id],
    ]);

    // Produce the part's CSV lines first (headers + one row).
    ProcessPartService::call(dataExportPart: $part);

    $result = CombinePartsService::call(dataExport: $dataExport);

    expect($result->failure())->toBeFalse();

    $dataExport->refresh();

    expect($dataExport->status)->toBe(DataExportStatus::Completed->value)
        ->and($dataExport->completed_at)->not->toBeNull()
        ->and($dataExport->expires_at)->not->toBeNull()
        ->and($dataExport->fileUrl())->toStartWith('/rails/active_storage/blobs/redirect/');

    $blob = App\Support\ActiveStorage::blob($dataExport, 'file');

    expect($blob->filename)->toBe($dataExport->filename())
        ->and($blob->content_type)->toBe('text/csv')
        ->and(str_starts_with((string) $blob->key, 'data_exports/'))->toBeTrue();

    $csv = App\Support\ActiveStorage::download($blob);
    $lines = explode("\n", mb_trim($csv));

    expect(count($lines))->toBe(2)
        ->and(str_contains($lines[1], $invoice->id))->toBeTrue();

    Mail::assertQueued(App\Mail\DataExportCompletedMail::class);
});
