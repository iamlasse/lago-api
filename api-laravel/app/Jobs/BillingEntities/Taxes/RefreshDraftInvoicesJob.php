<?php

declare(strict_types=1);

namespace App\Jobs\BillingEntities\Taxes;

use App\Models\Invoice;
use App\Models\BillingEntity;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Port of Rails' BillingEntities::Taxes::RefreshDraftInvoicesJob
 * (app/jobs/billing_entities/taxes/refresh_draft_invoices_job.rb) — flags
 * every draft invoice of the billing entity for a refresh after its applied
 * taxes changed.
 */
class RefreshDraftInvoicesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly string $billingEntityId)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $billingEntity = BillingEntity::query()->find($this->billingEntityId);

        if ($billingEntity === null) {
            return;
        }

        // Rails: billing_entity.invoices.draft.update_all(ready_to_be_refreshed: true).
        Invoice::query()
            ->where('billing_entity_id', $billingEntity->id)
            ->where('status', \App\Enums\InvoiceStatus::Draft)
            ->update(['ready_to_be_refreshed' => true]);
    }
}
