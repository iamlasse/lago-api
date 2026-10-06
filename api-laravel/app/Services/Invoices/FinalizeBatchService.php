<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Jobs\Invoices\FinalizeAllJob;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Enums\InvoiceStatus;
use Illuminate\Support\Collection;

/**
 * Port of Rails' Invoices::FinalizeBatchService
 * (app/services/invoices/finalize_batch_service.rb) — "Finalize all draft
 * invoices": call_async enqueues FinalizeAllJob with the organization's
 * draft invoice ids and answers that list; `call` processes the ids one by
 * one through RefreshDraftAndFinalizeService, short-circuiting on the first
 * failure (the failed result is re-raised through raiseIfError).
 */
class FinalizeBatchService extends BaseService
{
    public function __construct(
        private readonly object $organization,
    ) {
        parent::__construct();
    }

    /** Rails: `call_async`. */
    public function callAsync(): BaseResult
    {
        $result = BaseResult::of('invoice', 'invoices');

        $invoices = $this->draftInvoices()->get();

        FinalizeAllJob::dispatch($this->organization, $invoices->pluck('id')->all());

        $result->invoices = $invoices;

        return $result;
    }

    /** Rails: `call(invoice_ids)`. */
    public function callIds(array $invoiceIds): BaseResult
    {
        $result = BaseResult::of('invoice', 'invoices');

        /** @var list<object> $processed */
        $processed = [];

        foreach (Invoice::query()->whereIn('id', $invoiceIds)->get() as $invoice) {
            $result = RefreshDraftAndFinalizeService::call(invoice: $invoice);

            if ($result->failure()) {
                return $result;
            }

            $processed[] = $result->invoice;
        }

        $result->invoices = collect($processed);

        return $result;
    }

    public function execute(): BaseResult
    {
        return $this->callIds($this->draftInvoices()->pluck('id')->all());
    }

    /** Rails: `invoices` — the organization's DRAFT invoices. */
    private function draftInvoices(): \Illuminate\Database\Eloquent\Builder
    {
        /** @phpstan-ignore-next-line */
        return $this->organization->invoices()->where('status', InvoiceStatus::Draft->value);
    }
}
