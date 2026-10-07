<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\RateCard;
use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Port of Rails' RateCardsQuery (app/queries/rate_cards_query.rb).
 */
class RateCardsQuery extends BaseService
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
        $result = static::makeResult('rate_cards');

        $rateCards = RateCard::query()
            ->where('rate_cards.organization_id', $this->organization->id)
            // Rails: includes(:product, :product_filter, :rates).
            ->with(['product', 'productFilter', 'rates']);

        if (($term = (string) $this->searchTerm) !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
            $rateCards->where(function ($query) use ($escaped): void {
                $query->where('rate_cards.name', 'ILIKE', '%'.$escaped.'%')
                    ->orWhere('rate_cards.code', 'ILIKE', '%'.$escaped.'%');
            });
        }

        if (($this->filters['product_ids'] ?? null) !== null && $this->filters['product_ids'] !== []) {
            $rateCards->whereIn('rate_cards.product_id', $this->filters['product_ids']);
        }

        if (($this->filters['product_filter_ids'] ?? null) !== null && $this->filters['product_filter_ids'] !== []) {
            $rateCards->whereIn('rate_cards.product_filter_id', $this->filters['product_filter_ids']);
        }

        // Rails: with_product_category — a card reaches a product_category
        // through its product, so scope the cards to the matching products
        // (shared Product.in_categories rule; "no category" is selectable).
        if (($this->filters['product_category_ids'] ?? null) !== null
            || ($this->filters['without_product_category'] ?? false)) {
            $rateCards->where('rate_cards.product_id', function ($query): void {
                $categoryIds = $this->filters['product_category_ids'] ?? [];
                $includeUncategorized = (bool) ($this->filters['without_product_category'] ?? false);

                $query->select('id')
                    ->from('products')
                    ->where('products.organization_id', $this->organization->id)
                    ->where(function ($q) use ($categoryIds, $includeUncategorized): void {
                        if ($categoryIds !== [] && $includeUncategorized) {
                            $q->whereIn('products.product_category_id', $categoryIds)
                                ->orWhereNull('products.product_category_id');
                        } elseif ($includeUncategorized) {
                            $q->whereNull('products.product_category_id');
                        } else {
                            $q->whereIn('products.product_category_id', $categoryIds);
                        }
                    });
            });
        }

        // Rails: with_code — an exact match.
        if (($this->filters['code'] ?? null) !== null) {
            $rateCards->where('rate_cards.code', $this->filters['code']);
        }

        if (($this->filters['product_code'] ?? null) !== null) {
            $rateCards->where('rate_cards.product_id', function ($query): void {
                $query->select('id')
                    ->from('products')
                    ->where('products.organization_id', $this->organization->id)
                    ->where('products.code', $this->filters['product_code']);
            });
        }

        if (($this->filters['product_filter_code'] ?? null) !== null) {
            $rateCards->where('rate_cards.product_filter_id', function ($query): void {
                $query->select('id')
                    ->from('product_filters')
                    ->where('product_filters.organization_id', $this->organization->id)
                    ->where('product_filters.code', $this->filters['product_filter_code']);
            });
        }

        // Rails: paginate + apply_consistent_ordering.
        $result->rate_cards = $this->paginate(
            $rateCards->latest('rate_cards.created_at')->orderBy('rate_cards.id'),
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
