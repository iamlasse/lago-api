<?php

declare(strict_types=1);

namespace App\Services\DataExports;

use App\Models\DataExport;
use App\Models\DataExportPart;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\Invoices\Query;
use App\Queries\CreditNotesQuery;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Port of Rails' DataExports::ExportResourcesService
 * (app/services/data_exports/export_resources_service.rb): marks the export
 * processing, slices the matching object ids into batches and creates one
 * DataExportPart per batch (each queued for processing once the wrapping
 * transaction commits).
 */
class ExportResourcesService extends BaseService
{
    public const string EXPIRED_FAILURE_MESSAGE = 'Data Export already expired';
    public const string PROCESSED_FAILURE_MESSAGE = 'Data Export already processed';
    public const int DEFAULT_BATCH_SIZE = 20;

    /** Page size used when draining the paged Query services (see below). */
    public const int QUERY_PAGE_SIZE = 1000;

    public function __construct(
        private readonly DataExport $dataExport,
        private readonly int $batchSize = self::DEFAULT_BATCH_SIZE,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('data_export', 'data_export_parts');

        try {
            if ($this->dataExport->isExpired()) {
                return $result->serviceFailure('data_export_expired', self::EXPIRED_FAILURE_MESSAGE);
            }

            if (! $this->dataExport->isPending()) {
                return $result->serviceFailure('data_export_processed', self::PROCESSED_FAILURE_MESSAGE);
            }

            $this->dataExport->markProcessing();

            $result->data_export = $this->dataExport;
            $result->data_export_parts = [];

            $parts = [];

            DB::transaction(function () use (&$parts): void {
                foreach (array_chunk($this->allObjectIds(), $this->batchSize) as $index => $objectIds) {
                    $partResult = CreatePartService::call(
                        dataExport: $this->dataExport,
                        objectIds: $objectIds,
                        index: $index,
                    )->raiseIfError();

                    $parts[] = $partResult->data_export_part;
                }
            });

            // Rails: CreatePartService's after_commit — one ProcessPartJob
            // per part, after the wrapping transaction commits.
            /** @var DataExportPart $part */
            foreach ($parts as $part) {
                ProcessPartJob::dispatch($part);
            }

            $result->data_export_parts = $parts;

            return $result;
        } catch (Throwable $e) {
            $this->dataExport->markFailed();

            return $result->serviceFailure($e->getMessage(), $e->getMessage(), $e);
        }
    }

    /** @return list<string> */
    private function allObjectIds(): array
    {
        $resourceQuery = $this->dataExport->resource_query ?? [];
        $resourceType = $this->dataExport->resource_type;

        return match ($resourceType) {
            'credit_notes', 'credit_note_items' => $this->creditNoteIds($resourceQuery),
            'invoices', 'invoice_fees' => $this->allInvoiceIds($resourceQuery),
            default => throw new \RuntimeException(
                "'{$resourceType}' resource not supported",
            ),
        };
    }

    /**
     * Rails: InvoicesQuery.call(organization:, pagination: nil,
     * search_term:, filters:).invoices.pluck(:id).uniq.
     *
     * The ported Query service is kaminari-paginated (Rails' accepts
     * pagination: nil and skips LIMIT), so the ids are collected page by
     * page — same result set, bounded memory.
     *
     * @param  array<string, mixed>  $resourceQuery
     * @return list<string>
     */
    private function allInvoiceIds(array $resourceQuery): array
    {
        $searchTerm = $resourceQuery['search_term'] ?? null;
        $filters = array_diff_key($resourceQuery, ['search_term' => true]);

        $ids = [];

        for ($page = 1;; $page++) {
            $result = new Query(
                organization: $this->dataExport->organization,
                filters: $filters,
                pagination: ['page' => $page, 'limit' => self::QUERY_PAGE_SIZE],
                searchTerm: $searchTerm,
            )->execute()->raiseIfError();

            $pageIds = $result->invoices->modelKeys();

            $ids = array_merge($ids, $pageIds);

            if (count($pageIds) < self::QUERY_PAGE_SIZE) {
                break;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Rails: CreditNotesQuery.call(organization:, pagination: nil,
     * search_term:, filters:).credit_notes.pluck(:id).uniq.
     *
     * @param  array<string, mixed>  $resourceQuery
     * @return list<string>
     */
    private function creditNoteIds(array $resourceQuery): array
    {
        $searchTerm = $resourceQuery['search_term'] ?? null;
        $filters = array_diff_key($resourceQuery, ['search_term' => true]);

        $ids = [];

        for ($page = 1;; $page++) {
            $result = CreditNotesQuery::call(
                organization: $this->dataExport->organization,
                filters: $filters,
                pagination: ['page' => $page, 'limit' => self::QUERY_PAGE_SIZE],
                searchTerm: $searchTerm,
            )->raiseIfError();

            $pageIds = $result->credit_notes->modelKeys();

            $ids = array_merge($ids, $pageIds);

            if (count($pageIds) < self::QUERY_PAGE_SIZE) {
                break;
            }
        }

        return array_values(array_unique($ids));
    }
}
