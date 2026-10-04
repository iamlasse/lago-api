<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Organization;
use App\Services\BaseResult;
use App\Models\ProductFilter;
use App\Services\BaseService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Port of Rails' ProductFiltersQuery (app/queries/product_filters_query.rb)
 * — search over name/code, optional product filter.
 */
class ProductFiltersQuery extends BaseService
{
    private const DEFAULT_PER_PAGE = 100;

    public function __construct(
        private readonly Organization $organization,
        private readonly ?string $searchTerm = null,
        private readonly array $pagination = ['page' => null, 'limit' => null],
        private readonly array $filters = [],
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('product_filters');

        $productFilters = ProductFilter::query()
            ->where('product_filters.organization_id', $this->organization->id)
            // Rails: includes(values: :billable_metric_filter).
            ->with(['values.billableMetricFilter']);

        if (($term = (string) $this->searchTerm) !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
            $productFilters->where(function ($query) use ($escaped): void {
                $query->where('product_filters.name', 'ILIKE', '%'.$escaped.'%')
                    ->orWhere('product_filters.code', 'ILIKE', '%'.$escaped.'%');
            });
        }

        if (($this->filters['product_id'] ?? null) !== null) {
            $productFilters->where('product_filters.product_id', $this->filters['product_id']);
        }

        // Rails: paginate + apply_consistent_ordering.
        $result->product_filters = $this->paginate(
            $productFilters->orderByDesc('product_filters.created_at')->orderBy('product_filters.id'),
        );

        return $result;
    }

    private function paginate(\Illuminate\Database\Eloquent\Builder $scope): LengthAwarePaginator
    {
        $pageParam = $this->pagination['page'] ?? null;
        $limitParam = $this->pagination['limit'] ?? null;

        $page = is_numeric((string) $pageParam) && (string) $pageParam !== '' ? max(1, (int) $pageParam) : 1;

        $perPage = self::DEFAULT_PER_PAGE;
        if ($limitParam !== null && $limitParam !== '') {
            $perPage = max(1, (int) $limitParam);
        }

        return $scope->paginate($perPage, ['*'], 'page', $page);
    }
}
