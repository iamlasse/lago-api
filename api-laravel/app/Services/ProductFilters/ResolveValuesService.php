<?php

declare(strict_types=1);

namespace App\Services\ProductFilters;

use App\Models\Product;
use App\Services\BaseResult;
use App\Services\BaseService;

/**
 * Port of Rails' ProductFilters::ResolveValuesService
 * (app/services/product_filters/resolve_values_service.rb) — the public API
 * references billable metric filters by key; resolve each value entry's
 * `key` to the matching filter of the item's metric. Entries already
 * carrying a `billable_metric_filter_id` pass through.
 */
class ResolveValuesService extends BaseService
{
    public function __construct(
        private readonly Product $product,
        private readonly ?array $valuesParams,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('values_params');

        $resolved = [];

        foreach ((array) $this->valuesParams as $valueParams) {
            $valueParams = (array) $valueParams;

            if (($valueParams['billable_metric_filter_id'] ?? null) !== null
                || (($valueParams['key'] ?? null) === null || $valueParams['key'] === '')) {
                $resolved[] = $valueParams;

                continue;
            }

            $metricFilter = $this->product->billableMetric?->filters()
                ->where('key', $valueParams['key'])
                ->first();

            if ($metricFilter === null) {
                return $result->singleValidationFailure('value_is_invalid', 'values.key');
            }

            unset($valueParams['key']);
            $valueParams['billable_metric_filter_id'] = $metricFilter->id;

            $resolved[] = $valueParams;
        }

        $result->values_params = $resolved;

        return $result;
    }
}
