<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Enums\InvoiceStatus;
use App\Services\BaseResult;

/**
 * Port of Rails' Invoices::FinalizeService
 * (app/services/invoices/finalize_service.rb) — the draft → finalized
 * transition (invoice.finalized! is Rails' enum bang, bypassing the AASM
 * guard), which triggers the before_save hooks assigning the invoice number
 * (Sequenced) and finalized_at.
 */
class FinalizeService extends \App\Services\BaseService
{
    public function __construct(private readonly ?Invoice $invoice) {}

    public function execute(): BaseResult
    {
        $result = BaseResult::of('invoice');

        if ($this->invoice === null) {
            return $result->notFoundFailure('invoice');
        }

        if ($this->invoice->isFinalized()) {
            $result->invoice = $this->invoice;

            return $result;
        }

        $this->invoice->status = InvoiceStatus::Finalized;

        try {
            $this->invoice->save();
        } catch (\Illuminate\Database\QueryException $e) {
            return $result->failWithError(
                new \App\Services\Failures\ValidationFailure($result, ['base' => [$e->getMessage()]]),
            );
        }

        $this->invoice->refreshSearchTerms();

        $result->invoice = $this->invoice;

        return $result;
    }
}
