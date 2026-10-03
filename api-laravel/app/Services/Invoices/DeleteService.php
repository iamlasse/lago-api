<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Enums\InvoiceStatus;
use App\Services\BaseResult;
use App\Jobs\SendWebhookJob;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' Invoices::DeleteService
 * (app/services/invoices/delete_service.rb) — soft-deletes a draft invoice
 * under a row lock (the requires_new savepoint only rolls back the cascade).
 *
 * TODO(port) emission points left at their exact Rails positions:
 * activity_loggable (invoice.deleted), the credit-note cascade
 * (CreditNotes::DeleteService — credit notes are unported) and
 * integration_resources (the synced-externally guard is unported and
 * conservatively returns false).
 */
class DeleteService extends \App\Services\BaseService
{
    public function __construct(private readonly ?Invoice $invoice)
    {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('invoice');

        if ($this->invoice === null) {
            return $result->notFoundFailure('invoice');
        }

        $rolledBack = false;

        // Rails: invoice.with_lock(requires_new: true) — a savepoint so a
        // rollback only impacts this block, not any outer transaction.
        DB::transaction(function () use ($result, &$rolledBack): void {
            $invoice = Invoice::query()
                ->whereKey($this->invoice->id)
                ->lockForUpdate()
                ->first()
                ?? $this->invoice;

            if (! $invoice->isDraft()) {
                $result->notAllowedFailure('not_deletable');
                $rolledBack = true;

                return;
            }

            if ($this->syncedExternally($invoice)) {
                $result->notAllowedFailure('invoice_synced_to_external_system');
                $rolledBack = true;

                return;
            }

            // TODO(port): mark_credit_notes_as_deleted! — the credit-note
            // cascade (CreditNotes::DeleteService + the not_deletable
            // rollback) lands with the credit-notes slice.

            $invoice->status = InvoiceStatus::Deleted;
            $invoice->save();
        });

        if ($rolledBack || $result->failure()) {
            return $result;
        }

        $result->invoice = $this->invoice;

        SendWebhookJob::performLater('invoice.deleted', $result->invoice);

        return $result;
    }

    /**
     * Rails: invoice.integration_resources.exists? — integration resources
     * are unported.
     *
     * TODO(port): integration_resources.
     */
    private function syncedExternally(Invoice $invoice): bool
    {
        return false;
    }
}
