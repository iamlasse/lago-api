<?php

declare(strict_types=1);

namespace App\Jobs\BillableMetricFilters;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use App\Models\BillableMetric;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Port of Rails' BillableMetricFilters::RefreshDraftInvoicesJob
 * (app/jobs/billable_metric_filters/refresh_draft_invoices_job.rb) — flags
 * every draft invoice that bills the metric for a refresh after its filters
 * changed.
 */
class RefreshDraftInvoicesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly string $billableMetricId)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $billableMetric = BillableMetric::query()->find($this->billableMetricId);

        if ($billableMetric === null) {
            return;
        }

        Invoice::query()
            ->where('status', \App\Enums\InvoiceStatus::Draft)
            ->where('organization_id', $billableMetric->organization_id)
            ->join('invoice_subscriptions', 'invoice_subscriptions.invoice_id', '=', 'invoices.id')
            ->join('subscriptions', 'subscriptions.id', '=', 'invoice_subscriptions.subscription_id')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->join('charges', 'charges.plan_id', '=', 'plans.id')
            ->join('billable_metrics', 'billable_metrics.id', '=', 'charges.billable_metric_id')
            ->where('billable_metrics.id', $billableMetric->id)
            ->distinct()
            ->select('invoices.*')
            ->chunkById(500, function ($invoices): void {
                foreach ($invoices as $invoice) {
                    Invoice::query()->where('id', $invoice->id)->update(['ready_to_be_refreshed' => true]);
                }
            });
    }
}
