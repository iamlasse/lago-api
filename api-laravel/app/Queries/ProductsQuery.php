<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Product;
use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Port of Rails' ProductsQuery (app/queries/products_query.rb).
 *
 * Filters: product_category_ids (+ without_product_category),
 * product_type; search over name/code (ransack name_cont OR code_cont).
 */
class ProductsQuery extends BaseService
{
    /** Kaminari's default per page. */
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
        $result = static::makeResult('products');

        // Rails: base_scope.result.includes(:product_category, :billable_metric).
        $products = Product::query()
            ->where('products.organization_id', $this->organization->id)
            ->with(['productCategory', 'billableMetric']);

        // Rails: ransack m: "or", name_cont/code_cont.
        if (($term = (string) $this->searchTerm) !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
            $products->where(function ($query) use ($escaped): void {
                $query->where('products.name', 'ILIKE', '%'.$escaped.'%')
                    ->orWhere('products.code', 'ILIKE', '%'.$escaped.'%');
            });
        }

        if (($this->filters['product_category_ids'] ?? null) !== null
            || ($this->filters['without_product_category'] ?? false)) {
            // Rails: Product.in_categories — "no category" is a selectable value.
            $products->where(function ($query): void {
                $categoryIds = $this->filters['product_category_ids'] ?? [];
                $includeUncategorized = (bool) ($this->filters['without_product_category'] ?? false);

                if ($categoryIds !== [] && $includeUncategorized) {
                    $query->whereIn('products.product_category_id', $categoryIds)
                        ->orWhereNull('products.product_category_id');
                } elseif ($includeUncategorized) {
                    $query->whereNull('products.product_category_id');
                } else {
                    $query->whereIn('products.product_category_id', $categoryIds);
                }
            });
        }

        if (($this->filters['product_type'] ?? null) !== null) {
            // The controller validated the value against Product::PRODUCT_TYPES
            // before calling (the column is a PG enum: an unknown value would
            // fail the SQL cast).
            $products->where('products.product_type', $this->filters['product_type']);
        }

        // Rails: paginate + apply_consistent_ordering (created_at desc, id asc).
        $products = $this->paginate(
            $products->latest('products.created_at')->orderBy('products.id'),
        );

        $result->products = $products;

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
