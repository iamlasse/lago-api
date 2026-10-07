<?php

declare(strict_types=1);

namespace App\Services\DataExports\Csv;

use App\Models\DataExportPart;
use App\Services\BaseResult;
use App\Services\BaseService;

/**
 * Port of Rails' DataExports::Csv::BaseCsvService
 * (app/services/data_exports/csv/base_csv_service.rb): iterates the part's
 * collection and lets the subclass serialize each item into CSV rows.
 *
 * Rails writes into a Tempfile and hands the file back through the result;
 * the port returns the CSV payload as the result's `csv` string (the only
 * consumer, ProcessPartService, stores it in data_export_parts.csv_lines).
 */
abstract class BaseCsvService extends BaseService
{
    public function __construct(
        protected readonly DataExportPart $dataExportPart,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('csv');

        // Rails: Tempfile.create + CSV.new(tempfile, headers: false).
        $stream = fopen('php://temp', 'r+');

        foreach ($this->collection() as $item) {
            $this->serializeItem($item, $stream);
        }

        rewind($stream);

        $result->csv = (string) stream_get_contents($stream);

        fclose($stream);

        return $result;
    }

    /**
     * Rails: `headers` — computed off a transient instance built around one
     * of the export's parts (see CombinePartsService).
     *
     * @return list<string>
     */
    public static function headers(DataExportPart $dataExportPart): array
    {
        return static::buildHeaders($dataExportPart);
    }

    /** @return list<string> */
    abstract protected static function buildHeaders(DataExportPart $dataExportPart): array;

    /** Rails: `collection` — the part's objects. */
    abstract protected function collection(): iterable;

    /** Rails: `serialize_item(item, csv)` — one `csv << row` per item. */
    abstract protected function serializeItem(mixed $item, $stream): void;

    /**
     * Rails: `org_has_multiple_billing_entities?` — the billing_entity_code
     * column is appended only then.
     */
    protected function orgHasMultipleBillingEntities(): bool
    {
        $organization = $this->dataExportPart->dataExport->organization;

        if ($organization === null) {
            return false;
        }

        return $organization->billingEntities()->count() > 1;
    }

    /**
     * Ruby CSV defaults (CSV.new): comma separator, `"` quoting only when
     * needed, `""` doubling, `\n` row terminator.
     */
    protected function writeRow($stream, array $row): void
    {
        fputcsv($stream, array_map(
            fn (mixed $value): string => (string) $value,
            $row,
        ), ',', '"', '\\', "\n");
    }
}
