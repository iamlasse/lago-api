<?php

declare(strict_types=1);

namespace App\Services\BillableMetricFilters;

use App\Models\ChargeFilter;
use App\Services\BaseResult;
use App\Models\BillableMetric;
use App\Models\ChargeFilterValue;
use Illuminate\Support\Facades\DB;
use App\Models\BillableMetricFilter;

/**
 * Port of Rails' BillableMetricFilters::CreateOrUpdateBatchService
 * (app/services/billable_metric_filters/create_or_update_batch_service.rb) —
 * the `filters` array write of the billable-metrics create/update endpoints.
 *
 * Not ported (TODO(port)): PaperTrail request disabling around the
 * order-keeping `touch`; BillableMetricFilters::RefreshDraftInvoicesJob IS
 * ported and dispatched after commit (see the job).
 */
class CreateOrUpdateBatchService extends \App\Services\BaseService
{
    public const BATCH_SIZE = 1000;

    public function __construct(
        private readonly BillableMetric $billableMetric,
        private readonly array $filtersParams,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('filters');
        $result->filters = [];

        if ($this->filtersParams === []) {
            if ($this->billableMetric->productFilterValues()->exists()) {
                return $result->validationFailure(['filters' => ['referenced_by_product_filter']]);
            }

            DB::transaction(fn () => $this->discardAllFilters());

            return $result;
        }

        if ($this->anyFilterParamsValuesBlank()) {
            return $result->validationFailure(['values' => ['value_is_mandatory']]);
        }

        // Reject duplicate keys: `call` applies every entry (last write wins per
        // key), so the orphaning guard — which reads one entry per key — could
        // otherwise miss a value removed by a later duplicate entry.
        if ($this->duplicatedKeys()) {
            return $result->validationFailure(['key' => ['value_already_exist']]);
        }

        $orphans = $this->orphaningFilters();
        if ($orphans !== []) {
            return $result->validationFailure(['filters' => ['referenced_by_product_filter']]);
        }

        DB::transaction(function () use ($result): void {
            foreach ($this->filtersParams as $filterParam) {
                $filter = $this->billableMetric->filters()
                    ->firstOrNew(['key' => $filterParam['key']]);

                if (! $filter->exists) {
                    $filter->organization_id = $this->billableMetric->organization_id;
                }

                $newValues = array_values(array_unique($filterParam['values'] ?? []));

                if ($filter->exists) {
                    $deletedValues = array_values(array_diff($filter->values ?? [], $filterParam['values'] ?? []));

                    if ($deletedValues !== []) {
                        $filterValues = $filter->filterValues()
                            ->where(function ($query) use ($deletedValues): void {
                                foreach ($deletedValues as $value) {
                                    $query->orWhereRaw('?::text = ANY(values)', [$value]);
                                }
                            })
                            ->get();

                        $this->discardFilterValuesInBatches($filterValues, $newValues);
                    }
                }

                $filter->values = $newValues;
                $filter->save();

                $filters = $result->filters ?? [];
                $filters[] = $filter;
                $result->filters = $filters;
            }

            // NOTE: discard all filters that were not created or updated.
            $keptIds = array_map(fn ($filter) => $filter->id, $result->filters);

            $this->billableMetric->filters()
                ->whereNotIn('id', $keptIds)
                ->orderBy('id')
                ->get()
                ->each(fn (BillableMetricFilter $filter) => $this->discardFilter($filter));
        });

        dispatch(new \App\Jobs\BillableMetricFilters\RefreshDraftInvoicesJob((string) $this->billableMetric->id));

        return $result;
    }

    /** @return list<BillableMetricFilter> */
    private function orphaningFilters(): array
    {
        return $this->billableMetric->filters()->get()->filter(function (BillableMetricFilter $filter): bool {
            $param = null;

            foreach ($this->filtersParams as $filterParam) {
                if ($filterParam['key'] === $filter->key) {
                    $param = $filterParam;

                    break;
                }
            }

            if ($param === null) {
                return $filter->productFilterValues()->exists();
            }

            $deletedValues = array_values(array_diff($filter->values ?? [], (array) ($param['values'] ?? [])));

            if ($deletedValues === []) {
                return false;
            }

            // A product filter value with a null value tracks the whole set.
            return $filter->productFilterValues()
                ->whereNull('value')
                ->exists()
                || $filter->productFilterValues()
                    ->whereIn('value', $deletedValues)
                    ->exists();
        })->values()->all();
    }

    private function anyFilterParamsValuesBlank(): bool
    {
        foreach ($this->filtersParams as $filterParam) {
            if (($filterParam['values'] ?? null) === null || $filterParam['values'] === []) {
                return true;
            }
        }

        return false;
    }

    private function duplicatedKeys(): bool
    {
        $keys = array_map(fn (array $filterParam) => $filterParam['key'], $this->filtersParams);

        return count($keys) !== count(array_unique($keys));
    }

    private function discardAllFilters(): void
    {
        $this->billableMetric->filters()->get()->each(fn (BillableMetricFilter $filter) => $this->discardFilter($filter));
    }

    private function discardFilter(BillableMetricFilter $filter): void
    {
        $this->discardFilterValuesInBatches($filter->filterValues()->get());

        $filter->delete();
    }

    private function discardFilterValuesInBatches($filterValues, array $newValues = []): void
    {
        $filterValues = $filterValues instanceof \Illuminate\Support\Collection
            ? $filterValues
            : collect($filterValues);

        if ($filterValues->isEmpty()) {
            return;
        }

        $valuesToTrim = $filterValues->filter(
            fn (ChargeFilterValue $fv): bool => (bool) array_intersect($fv->values ?? [], $newValues),
        )->values();

        $valuesToDiscard = $filterValues->reject(
            fn (ChargeFilterValue $fv): bool => (bool) array_intersect($fv->values ?? [], $newValues),
        )->values();

        $this->bulkUpdateTrimmedFilterValues($valuesToTrim, $newValues);
        $this->discardFilterValuesAndEmptiedChargeFilters($valuesToDiscard);
    }

    /** @param \Illuminate\Support\Collection<int, ChargeFilterValue> $filterValues */
    private function bulkUpdateTrimmedFilterValues($filterValues, array $newValues): void
    {
        if ($filterValues->isEmpty()) {
            return;
        }

        $grouped = [];

        foreach ($filterValues as $filterValue) {
            $resultValues = array_values(array_intersect($filterValue->values ?? [], $newValues));
            $grouped[implode("\0", $resultValues)][] = [$filterValue, $resultValues];
        }

        foreach ($grouped as $group) {
            foreach ($group as [$filterValue, $resultValues]) {
                // Model save (not a bulk update) so the PostgresArray cast runs.
                $filterValue->values = $resultValues;
                $filterValue->updated_at = now();
                $filterValue->save();
            }
        }
    }

    /** @param \Illuminate\Support\Collection<int, ChargeFilterValue> $filterValues */
    private function discardFilterValuesAndEmptiedChargeFilters($filterValues): void
    {
        if ($filterValues->isEmpty()) {
            return;
        }

        $filterValueIds = $filterValues->pluck('id')->all();

        ChargeFilterValue::withTrashed()
            ->whereIn('id', $filterValueIds)
            ->update(['deleted_at' => now()]);

        // The in-memory rows know their charge_filter_id (the bulk soft-delete
        // above doesn't change it); re-querying fights the model's global
        // ordering scope on SELECT DISTINCT.
        $chargeFilterIds = $filterValues
            ->pluck('charge_filter_id')
            ->unique()
            ->values()
            ->all();

        if ($chargeFilterIds === []) {
            return;
        }

        ChargeFilter::query()
            ->whereIn('id', $chargeFilterIds)
            ->whereNull('deleted_at')
            ->whereNotExists(function ($query): void {
                $query->selectRaw(1)
                    ->from('charge_filter_values')
                    ->whereColumn('charge_filter_values.charge_filter_id', 'charge_filters.id')
                    ->whereNull('charge_filter_values.deleted_at');
            })
            ->update(['deleted_at' => now()]);
    }
}
