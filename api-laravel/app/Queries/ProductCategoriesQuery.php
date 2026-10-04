<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\ProductCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Port of Rails' ProductCategoriesQuery
 * (app/queries/product_categories_query.rb) — search over name/code
 * (ransack name_cont OR code_cont).
 */
class ProductCategoriesQuery extends BaseService
{
    private const DEFAULT_PER_PAGE = 100;

    public function __construct(
        private readonly Organization $organization,
        private readonly ?string $searchTerm = null,
        private readonly array $pagination = ['page' => null, 'limit' => null],
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('product_categories');

        $productCategories = ProductCategory::query()
            ->where('product_categories.organization_id', $this->organization->id)
            // Preloaded so the serializer's products_count reads the loaded
            // association.
            ->with('products');

        if (($term = (string) $this->searchTerm) !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
            $productCategories->where(function ($query) use ($escaped): void {
                $query->where('product_categories.name', 'ILIKE', '%'.$escaped.'%')
                    ->orWhere('product_categories.code', 'ILIKE', '%'.$escaped.'%');
            });
        }

        // Rails: paginate + apply_consistent_ordering (created_at desc, id asc).
        $result->product_categories = $this->paginate(
            $productCategories->orderByDesc('product_categories.created_at')->orderBy('product_categories.id'),
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
