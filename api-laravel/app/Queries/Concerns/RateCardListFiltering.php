<?php

declare(strict_types=1);

namespace App\Queries\Concerns;

use App\Models\Product;
use App\Models\RateCard;
use App\Models\RatePhase;
use Illuminate\Database\Eloquent\Builder;

/**
 * Port of Rails' RateCardListFiltering concern
 * (app/queries/concerns/rate_card_list_filtering.rb) — the filters of the
 * plan and contract rate card pages. A card only holds its rate card, so the
 * catalog filters (product, product filter, category, product type, search)
 * narrow the rate cards first; rate overrides live on the card's own phases.
 *
 * The consuming class must expose `$this->organization` and `$this->filters`
 * / `$this->searchTerm` (the BaseQuery-shaped properties).
 */
trait RateCardListFiltering
{
    /**
     * Rails: apply_rate_card_filters(scope, phase_parent:) — `phase_parent`
     * is the rate phases column pointing at the card (`plan_rate_card_id` /
     * `contract_rate_card_id`).
     */
    protected function applyRateCardFilters(Builder $scope, string $phaseParent): Builder
    {
        $rateCards = $this->matchingRateCards();

        if ($rateCards !== null) {
            $scope->whereIn('rate_card_id', $rateCards->select('id'));
        }

        if (! array_key_exists('has_rate_overrides', $this->filters)
            || $this->filters['has_rate_overrides'] === null) {
            return $scope;
        }

        return $this->withRateOverrides($scope, $phaseParent);
    }

    /** Rails: matching_rate_cards — null when no catalog filter is active. */
    protected function matchingRateCards(): ?Builder
    {
        $hasFilters = collect(['product_ids', 'product_filter_ids', 'without_product_filter',
            'product_category_ids', 'without_product_category', 'product_type'])
            ->some(fn (string $key): bool => ($this->filters[$key] ?? null) !== null
                && ($this->filters[$key] ?? null) !== []);

        if (($this->searchTerm ?? null) === null && ! $hasFilters) {
            return null;
        }

        $rateCards = RateCard::query()->where('organization_id', $this->organization->id);

        $term = (string) ($this->searchTerm ?? '');
        if ($term !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
            $rateCards->where(function ($query) use ($escaped): void {
                $query->where('rate_cards.name', 'ILIKE', '%'.$escaped.'%')
                    ->orWhere('rate_cards.code', 'ILIKE', '%'.$escaped.'%');
            });
        }

        if (($this->filters['product_ids'] ?? null) !== null && $this->filters['product_ids'] !== []) {
            $rateCards->where('product_id', $this->filters['product_ids']);
        }

        if (($productType = $this->filters['product_type'] ?? null) !== null) {
            $rateCards->whereIn('product_id', Product::query()
                ->where('organization_id', $this->organization->id)
                ->where('product_type', $productType)
                ->select('id'));
        }

        $productFilterIds = $this->filters['product_filter_ids'] ?? null;
        $withoutProductFilter = $this->filters['without_product_filter'] ?? null;
        if (($productFilterIds !== null && $productFilterIds !== []) || $withoutProductFilter) {
            // "Not defined" is a card priced on the product itself, without a
            // filter (product_filter_id NULL).
            $ids = $withoutProductFilter ? array_merge((array) ($productFilterIds ?? []), [null]) : $productFilterIds;
            $rateCards->where(function ($query) use ($ids): void {
                $null = in_array(null, $ids, true);
                $real = array_values(array_filter($ids, fn ($v): bool => $v !== null));

                $query->where(function ($q) use ($real, $null): void {
                    if ($real !== []) {
                        $q->whereIn('rate_cards.product_filter_id', $real);
                    }

                    if ($null) {
                        $q->orWhereNull('rate_cards.product_filter_id');
                    }

                    if ($real === [] && ! $null) {
                        $q->whereRaw('1 = 0');
                    }
                });
            });
        }

        $categoryIds = $this->filters['product_category_ids'] ?? null;
        $withoutCategory = $this->filters['without_product_category'] ?? null;
        if (($categoryIds !== null && $categoryIds !== []) || $withoutCategory) {
            // Rails: organization.products.in_categories(ids,
            // include_uncategorized:) — "no category" is a selectable value.
            $rateCards->whereIn('product_id', Product::query()
                ->where('organization_id', $this->organization->id)
                ->where(function ($query) use ($categoryIds, $withoutCategory): void {
                    $ids = $categoryIds ?? [];
                    $includeUncategorized = (bool) $withoutCategory;

                    if ($ids !== [] && $includeUncategorized) {
                        $query->whereIn('product_category_id', $ids)
                            ->orWhereNull('product_category_id');
                    } elseif ($includeUncategorized) {
                        $query->whereNull('product_category_id');
                    } else {
                        $query->whereIn('product_category_id', $ids);
                    }
                })
                ->select('id'));
        }

        return $rateCards;
    }

    /**
     * Rails: with_rate_overrides — phases hang off either a plan card or a
     * contract card: the other parent is NULL, and a NULL in a NOT IN list
     * would match nothing.
     */
    protected function withRateOverrides(Builder $scope, string $phaseParent): Builder
    {
        $overridingIds = RatePhase::query()
            ->where('organization_id', $this->organization->id)
            ->whereNotNull('rate_override_id')
            ->whereNotNull($phaseParent)
            ->select($phaseParent);

        // Rails: scope.where(id: overriding_ids) — the applied card's own id
        // (the phase parent id is the card id).
        $cardIdColumn = $scope->getModel()->getTable().'.id';

        if (filter_var($this->filters['has_rate_overrides'], FILTER_VALIDATE_BOOLEAN)) {
            $scope->whereIn($cardIdColumn, $overridingIds);
        } else {
            $scope->whereNotIn($cardIdColumn, $overridingIds);
        }

        return $scope;
    }

    /**
     * Port of Rails' RateCardCategoryOrdering#order_by_product_category —
     * groups the cards by product category (products outside any category
     * last), then by product, a product's own card before its filter cards.
     */
    protected function orderByProductCategory(Builder $scope): Builder
    {
        // Rails: left_outer_joins(rate_card: [:product_filter,
        // {product: :product_category}]).
        $table = $scope->getModel()->getTable();

        return $scope
            // The joins' columns share names with the card's (id, name, …);
            // without the explicit select they clobber the model attributes.
            ->select($table.'.*')
            ->leftJoin('rate_cards', 'rate_cards.id', '=', $table.'.rate_card_id')
            ->leftJoin('product_filters', 'product_filters.id', '=', 'rate_cards.product_filter_id')
            ->leftJoin('products', 'products.id', '=', 'rate_cards.product_id')
            ->leftJoin('product_categories', 'product_categories.id', '=', 'products.product_category_id')
            ->orderByRaw('product_categories.name asc nulls last, product_categories.id asc nulls last')
            ->orderByRaw('products.name asc, products.id asc')
            ->orderByRaw('product_filters.name asc nulls first, product_filters.id asc nulls first');
    }
}
