<?php

declare(strict_types=1);

namespace App\Services\BillableMetricFilters;

use App\Services\BaseResult;
use App\Models\BillableMetric;
use App\Models\BillableMetricFilter;

/**
 * Port of Rails' BillableMetricFilters::DestroyAllService
 * (app/services/billable_metric_filters/destroy_all_service.rb) — only runs
 * for a DISCARDED metric (the metric-destroy flow): discards every remaining
 * filter and soft-deletes its filter and charge filter values.
 */
class DestroyAllService extends \App\Services\BaseService
{
    public function __construct(private readonly ?BillableMetric $billableMetric)
    {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('billable_metric');

        if ($this->billableMetric === null) {
            return $result;
        }

        if ($this->billableMetric->deleted_at === null) {
            return $result;
        }

        $deletedAt = now();

        $this->billableMetric->filters()
            ->orderBy('id')
            ->get()
            ->each(function (BillableMetricFilter $filter) use ($deletedAt): void {
                $filter->filterValues()->update(['deleted_at' => $deletedAt]);
                $filter->chargeFilters()->update(['deleted_at' => $deletedAt]);

                $filter->delete();
            });

        $result->billable_metric = $this->billableMetric;

        return $result;
    }
}
