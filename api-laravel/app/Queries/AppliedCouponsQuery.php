<?php

declare(strict_types=1);

namespace App\Queries;

use App\Services\BaseResult;
use App\GraphQL\Support\Page;
use App\Models\AppliedCoupon;
use App\Services\BaseService;
use App\Enums\AppliedCouponStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Port of Rails' AppliedCouponsQuery (app/queries/applied_coupons_query.rb).
 *
 * Base scope: the organization's applied coupons, joined to their customer,
 * soft-deleted customers excluded (Rails: joins(:customer).where(customers:
 * {deleted_at: nil})). Filters apply after pagination + the consistent
 * ordering in Rails; Laravel applies everything on one builder (same result
 * set): external_customer_id, coupon_code (a list) and status — the status
 * filter is skipped when the name is not a known AppliedCoupon status
 * (Rails' `valid_status?`).
 */
class AppliedCouponsQuery extends BaseService
{
    public function __construct(
        private readonly object $organization,
        private readonly array $pagination = ['page' => null, 'limit' => null],
        private readonly array $filters = [],
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('applied_coupons');

        $appliedCoupons = AppliedCoupon::query()
            // Rails: `organization.applied_coupons.joins(:customer)` selects
            // applied_coupons.* — the join must not overwrite the model's
            // columns (id, created_at, …) with the customers' values.
            ->select('applied_coupons.*')
            ->where('applied_coupons.organization_id', $this->organization->id)
            ->join('customers', 'customers.id', '=', 'applied_coupons.customer_id')
            ->whereNull('customers.deleted_at');

        $externalCustomerId = $this->filters['external_customer_id'] ?? null;

        if ($externalCustomerId !== null && $externalCustomerId !== '') {
            $appliedCoupons->where('customers.external_id', $externalCustomerId);
        }

        $couponCodes = $this->filters['coupon_code'] ?? null;

        if (is_array($couponCodes) && $couponCodes !== []) {
            $appliedCoupons->join('coupons', 'coupons.id', '=', 'applied_coupons.coupon_id')
                ->where('coupons.code', $couponCodes);
        }

        $status = $this->filters['status'] ?? null;
        $mappedStatus = is_string($status) && $status !== '' ? AppliedCouponStatus::fromOption($status) : null;

        // Rails: with_status(scope) if valid_status? — an unknown status name
        // skips the filter instead of filtering.
        if ($mappedStatus !== null) {
            $appliedCoupons->where('applied_coupons.status', $mappedStatus);
        }

        $result->applied_coupons = $this->paginate(
            // Rails: apply_consistent_ordering (created_at desc, id asc —
            // Rails qualifies symbol orders with the model's table).
            $appliedCoupons->latest('applied_coupons.created_at')->orderBy('applied_coupons.id'),
        );

        return $result;
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
