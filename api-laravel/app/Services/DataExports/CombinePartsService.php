<?php

declare(strict_types=1);

namespace App\Services\DataExports;

use App\Mail\DataExportCompletedMail;
use App\Models\DataExport;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\Mail;

/**
 * Port of Rails' DataExports::CombinePartsService
 * (app/services/data_exports/combine_parts_service.rb): streams the parts'
 * CSV lines — in index order — into one file (headers first), attaches it
 * to the export, marks it completed and emails the member.
 *
 * Note the order, it is crucial to make sure the data is in the expected
 * order. And like Rails, the parts are re-fetched one by one rather than
 * loaded at once — trading speed for not holding the whole CSV in memory.
 */
class CombinePartsService extends BaseService
{
    public function __construct(
        private readonly DataExport $dataExport,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('data_export');

        $result->data_export = $this->dataExport;

        $orderedParts = $this->dataExport->dataExportParts()
            ->orderBy('index')
            ->get();

        $exportClass = $this->dataExport->exportClass();

        $csv = '';

        if ($orderedParts->isNotEmpty() && $exportClass !== null) {
            $headers = $exportClass::headers($orderedParts->first());

            $csv = implode(',', $headers)."\n";
        }

        // N+1 by design (see above) — one query per part.
        foreach ($orderedParts->modelKeys() as $id) {
            $csv .= (string) $this->dataExport->dataExportParts()->find($id)->csv_lines;
        }

        $format = $this->dataExport->formatEnum()?->label() ?? 'csv';

        \App\Support\ActiveStorage::attach(
            $this->dataExport,
            'file',
            $csv,
            $this->dataExport->filename(),
            'text/csv',
            null,
            'data_exports/'.$this->dataExport->id.'-'.DataExport::hex5().'.'.$format,
        );

        $this->dataExport->markCompleted();

        // Rails: DataExportMailer.with(data_export:).completed.deliver_later
        // — the mailer returns early on an expired/non-completed export
        // (unreachable here, ported for parity).
        $user = $this->dataExport->user();

        if ($user !== null && ! $this->dataExport->isExpired() && $this->dataExport->isCompleted()) {
            Mail::to($user->email)->queue(new DataExportCompletedMail($this->dataExport));
        }

        return $result;
    }
}
