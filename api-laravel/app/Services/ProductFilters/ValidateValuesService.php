<?php

declare(strict_types=1);

namespace App\Services\ProductFilters;

use App\Models\Product;
use App\Services\BaseResult;
use App\Models\ProductFilter;
use App\Services\BaseService;

/**
 * Port of Rails' ProductFilters::ValidateValuesService
 * (app/services/product_filters/validate_values_service.rb).
 *
 * @param  ProductFilter|null  $productFilter  the filter being updated.
 */
class ValidateValuesService extends BaseService
{
    public function __construct(
        private readonly Product $product,
        private readonly array $valuesParams,
        private readonly ?ProductFilter $productFilter = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult();

        if ($this->valuesParams === []) {
            return $result->singleValidationFailure('value_is_mandatory', 'values');
        }

        $requestedIds = array_values(array_unique(array_map(
            fn (array $entry): string => (string) ($entry['billable_metric_filter_id'] ?? ''),
            $this->valuesParams,
        )));

        $knownIds = $this->product->billableMetric->filters()
            ->whereIn('id', $requestedIds)
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        sort($requestedIds);
        $knownSorted = $knownIds;
        sort($knownSorted);

        if ($knownSorted !== $requestedIds) {
            return $result->singleValidationFailure('value_is_invalid', 'values.billable_metric_filter');
        }

        // A key-only entry (no value) selects all configured values for the
        // key, so combining it with specific values for the same key is
        // redundant.
        $keyOnlyIds = array_map(
            fn (array $entry): string => (string) ($entry['billable_metric_filter_id'] ?? ''),
            array_filter($this->valuesParams, fn (array $entry): bool => ! array_key_exists('value', $entry) || $entry['value'] === null),
        );
        $specificIds = array_map(
            fn (array $entry): string => (string) ($entry['billable_metric_filter_id'] ?? ''),
            array_filter($this->valuesParams, fn (array $entry): bool => array_key_exists('value', $entry) && $entry['value'] !== null),
        );

        if (array_intersect($keyOnlyIds, $specificIds) !== []) {
            return $result->singleValidationFailure('key_only_conflicts_with_values', 'values');
        }

        if ($this->duplicateValueSet()) {
            return $result->singleValidationFailure('value_already_exist', 'values');
        }

        return $result;
    }

    /** Rails: `duplicate_value_set?` — no sibling filter carries the same set. */
    private function duplicateValueSet(): bool
    {
        $submitted = $this->normalized($this->valuesParams);

        return $this->product->filters()
            ->when($this->productFilter !== null, fn ($query) => $query->whereKeyNot($this->productFilter->id))
            ->with('values')
            ->get()
            ->contains(function (ProductFilter $filter) use ($submitted): bool {
                return $this->normalized($filter->values->map(fn ($value): array => [
                    'billable_metric_filter_id' => (string) $value->billable_metric_filter_id,
                    'value' => $value->value,
                ])->all()) === $submitted;
            });
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<list<string>>
     */
    private function normalized(array $entries): array
    {
        $pairs = array_map(
            fn (array $entry): array => [(string) ($entry['billable_metric_filter_id'] ?? ''), (string) ($entry['value'] ?? '')],
            $entries,
        );

        usort($pairs, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return $pairs;
    }
}
