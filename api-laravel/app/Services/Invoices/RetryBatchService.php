<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Jobs\Invoices\RetryAllJob;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Enums\InvoiceStatus;

/**
 * Port of Rails' Invoices::RetryBatchService
 * (app/services/invoices/retry_batch_service.rb) — "Retry all failed
 * invoices": call_async enqueues RetryAllJob with the organization's failed
 * invoice ids and answers that list; `call` processes the ids one by one
 * through Invoices\RetryService, short-circuiting on the first failure.
 */
class RetryBatchService extends BaseService
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

        $invoices = $this->failedInvoices()->get();

        RetryAllJob::dispatch($this->organization, $invoices->pluck('id')->all());

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
            $result = RetryService::call(invoice: $invoice);

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
        return $this->callIds($this->failedInvoices()->pluck('id')->all());
    }

    /** Rails: `invoices` — the organization's FAILED invoices. */
    private function failedInvoices(): \Illuminate\Database\Eloquent\Builder
    {
        return $this->organization->invoices()->where('status', InvoiceStatus::Failed->value);
    }
}
