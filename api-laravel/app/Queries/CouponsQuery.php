<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Coupon;
use App\Enums\CouponStatus;
use App\Services\BaseResult;
use App\GraphQL\Support\Page;
use App\Services\BaseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Port of Rails' CouponsQuery (app/queries/coupons_query.rb).
 *
 * The REST index passes only organization + pagination (no filters, no
 * search term); the GraphQL `coupons` resolver adds the `status` filter and
 * the free-text search term (ransack `name_cont OR code_cont` — an
 * ILIKE '%term%' on both columns).
 *
 * Rails chains paginate → order_by_status_and_expiration →
 * apply_consistent_ordering; Laravel orders the builder before paginating
 * (same result set).
 */
class CouponsQuery extends BaseService
{
    public function __construct(
        private readonly object $organization,
        private readonly array $pagination = ['page' => null, 'limit' => null],
        private readonly array $filters = [],
        private readonly ?string $searchTerm = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('coupons');

        // Rails: Coupon.where(organization:).ransack(search_params).result
        $coupons = Coupon::query()
            ->where('organization_id', $this->organization->id);

        $coupons = $this->withSearchTerm($coupons);

        // Rails: with_status(scope) if filters.status.present?
        $status = $this->filters['status'] ?? null;

        if (is_string($status) && $status !== '') {
            $coupons->where('status', CouponStatus::fromOption($status) ?? $status);
        }

        $result->coupons = $this->paginate(
            $coupons
                // Rails: order_by_status_and_expiration.
                ->orderByStatusAndExpiration()
                // Rails: apply_consistent_ordering (created_at desc, id asc —
                // Rails qualifies symbol orders with the model's table).
                ->latest('coupons.created_at')
                ->orderBy('coupons.id'),
        );

        return $result;
    }

    /**
     * Rails: the ransack `m: or` search — name_cont OR code_cont. Blank
     * search terms return the scope untouched.
     */
    private function withSearchTerm(Builder $scope): Builder
    {
        $term = $this->searchTerm;

        if ($term === null || mb_trim($term) === '') {
            return $scope;
        }

        $like = '%'.mb_strtolower(mb_trim($term)).'%';

        return $scope->where(function (Builder $query) use ($like): void {
            $query->whereRaw('LOWER(coupons.name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(coupons.code) LIKE ?', [$like]);
        });
    }

    /**
     * Rails: `paginate` — kaminari page/per; nil page/limit fall back to the
     * kaminari defaults (page 1, 25 per page) through Page::normalize.
     */
    private function paginate(Builder $scope): LengthAwarePaginator
    {
        [$page, $limit] = Page::normalizePageAndLimit(
            is_numeric($this->pagination['page'] ?? null) ? (int) $this->pagination['page'] : null,
            is_numeric($this->pagination['limit'] ?? null) ? (int) $this->pagination['limit'] : null,
        );

        return $scope->paginate(perPage: $limit, page: $page);
    }
}
