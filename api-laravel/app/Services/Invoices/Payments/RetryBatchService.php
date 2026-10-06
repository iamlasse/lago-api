<?php

declare(strict_types=1);

namespace App\Services\Invoices\Payments;

use App\Models\Invoice;
use App\Models\Organization;
use App\Jobs\Invoices\Payments\RetryAllJob;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Enums\InvoicePaymentStatus;
use App\Enums\InvoiceStatus;

/**
 * Port of Rails' Invoices::Payments::RetryBatchService
 * (app/services/invoices/payments/retry_batch_service.rb) — "Retry all
 * invoice payments": the organization's finalized, ready-for-payment
 * invoices with a pending/failed payment status. call_async enqueues
 * Payments::RetryAllJob and answers that list; `call` processes the ids one
 * by one through Invoices\Payments\RetryService.
 */
class RetryBatchService extends BaseService
{
    public function __construct(
        private readonly string $organizationId,
    ) {
        parent::__construct();
    }

    /** Rails: `call_async`. */
    public function callAsync(): BaseResult
    {
        $result = BaseResult::of('invoice', 'invoices');

        $invoices = $this->invoices()->get();

        RetryAllJob::dispatch($this->organizationId, $invoices->pluck('id')->all());

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
        return $this->callIds($this->invoices()->pluck('id')->all());
    }

    /** Rails: `invoices` — finalized + ready + payment pending/failed. */
    private function invoices(): \Illuminate\Database\Eloquent\Builder
    {
        return Invoice::query()
            ->where('organization_id', $this->organizationId)
            ->whereIn('payment_status', [
                InvoicePaymentStatus::Pending->value,
                InvoicePaymentStatus::Failed->value,
            ])
            ->where('ready_for_payment_processing', true)
            ->where('status', InvoiceStatus::Finalized->value);
    }
}
