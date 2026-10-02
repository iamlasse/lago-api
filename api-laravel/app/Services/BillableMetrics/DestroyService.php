<?php

declare(strict_types=1);

namespace App\Services\BillableMetrics;

use App\Models\Charge;
use App\Models\Invoice;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\BillableMetric;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' BillableMetrics::DestroyService
 * (app/services/billable_metrics/destroy_service.rb).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): product_filter_values guard (single_validation_failure
 *   "referenced_by_product_filter").
 * - TODO(port): BillableMetrics::ExpressionCacheService.expire_cache.
 * - TODO(port): UsageMonitoring::Alert soft deletes.
 * - TODO(port): BillableMetricFilters::DestroyAllJob.
 * - TODO(port): SendWebhookJob.perform_after_commit("billable_metric.deleted").
 * - TODO(port): activity log middleware (activity_loggable).
 */
class DestroyService extends BaseService
{
    public function __construct(
        private readonly ?BillableMetric $metric,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('billable_metric');
        $metric = $this->metric;

        if ($metric === null) {
            return $result->notFoundFailure('billable_metric');
        }

        // TODO(port): deleting the metric would discard its filters and orphan
        // any kept product filter value that references them, so Rails blocks
        // instead of cascading: `result.single_validation_failure!(field:
        // :billable_metric, error_code: "referenced_by_product_filter")` when
        // metric.product_filter_values.exists?.

        // TODO(port): BillableMetrics::ExpressionCacheService.expire_cache(
        //   metric.organization.id, metric.code).

        $draftInvoiceIds = $this->draftInvoiceIds($metric);

        DB::transaction(function () use ($metric, $draftInvoiceIds): void {
            $metric->delete();

            // Rails: metric.charges.update_all(deleted_at: Time.current)
            // (kept charges only — the has_many goes through the default scope).
            $metric->charges()->update(['deleted_at' => now()]);

            // TODO(port): metric.alerts.update_all(deleted_at: Time.current)
            //   (UsageMonitoring::Alert is a later milestone).

            Invoice::query()->whereIn('id', $draftInvoiceIds)->update(['ready_to_be_refreshed' => true]);
        });

        // TODO(port): BillableMetricFilters::DestroyAllJob.perform_later(metric.id).
        // TODO(port): SendWebhookJob.perform_after_commit(
        //   "billable_metric.deleted", metric) — webhook emission hook point.

        $result->billable_metric = $metric;

        return $result;
    }

    /**
     * Rails: `Invoice.draft.joins(plans: [:billable_metrics])
     *   .where(billable_metrics: {id: metric.id}).distinct.pluck(:id)` — the
     * draft invoices of the plans that carry a (kept) charge for this metric.
     *
     * @return list<string>
     */
    protected function draftInvoiceIds(BillableMetric $metric): array
    {
        return Invoice::query()
            ->where('status', 0) // Rails: Invoice.draft (draft: 0)
            ->whereIn('plan_id', Charge::query()
                ->where('billable_metric_id', $metric->id)
                ->select('plan_id'))
            ->distinct()
            ->pluck('id')
            ->all();
    }
}
