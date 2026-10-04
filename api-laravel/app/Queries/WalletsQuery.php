<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Wallet;
use App\Models\Customer;
use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Port of Rails' WalletsQuery (app/queries/wallets_query.rb).
 *
 * The `external_customer_id` filter narrows to the customer's wallets and is
 * validated first: a filter whose customer does not exist answers the
 * not_found envelope (resource "customer").
 */
class WalletsQuery extends BaseService
{
    /** Kaminari's default per page, used when the limit param is nil. */
    private const DEFAULT_PER_PAGE = 100;

    public function __construct(
        private readonly Organization $organization,
        private readonly array $pagination = ['page' => null, 'limit' => null],
        private readonly array $filters = [],
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('wallets');

        // Rails: validate_filters — only when the external_customer_id key is
        // PRESENT (even nil) must the customer exist.
        if (array_key_exists('external_customer_id', $this->filters)
            && ! $this->customer()->exists()) {
            return $result->notFoundFailure('customer');
        }

        $wallets = $this->baseScope();

        $wallets = $this->withExternalCustomerId($wallets) ?? $wallets;
        $wallets = $this->withCurrency($wallets) ?? $wallets;
        $wallets = $this->withBillingEntityIds($wallets) ?? $wallets;

        // Rails: paginate then apply_consistent_ordering (created_at desc,
        // id asc tiebreak) — applied before the SQL executes here so the
        // ordering actually lands inside the LIMIT/OFFSET query.
        $wallets = $wallets
            ->latest('wallets.created_at')
            ->orderBy('wallets.id');

        $result->wallets = $this->paginate($wallets);

        return $result;
    }

    private function baseScope(): Builder
    {
        // Rails: `organization.wallets` — no Organization#wallets relation is
        // ported, so the scope is built directly (same organization_id
        // constraint the has_many would carry).
        return Wallet::query()->where('wallets.organization_id', $this->organization->id);
    }

    /** Rails: `scope.where(customer_id: customer.select(:id))`. */
    private function withExternalCustomerId(Builder $scope): ?Builder
    {
        $externalCustomerId = $this->filters['external_customer_id'] ?? null;

        if ($externalCustomerId === null) {
            return null;
        }

        return $scope->whereIn('customer_id', $this->customer()->select('id'));
    }

    /** Rails: `scope.where(balance_currency: filters.currency)`. */
    private function withCurrency(Builder $scope): ?Builder
    {
        $currency = $this->filters['currency'] ?? null;

        if ($currency === null) {
            return null;
        }

        return $scope->where('balance_currency', $currency);
    }

    /**
     * Rails: joins customers and matches COALESCE(wallets.billing_entity_id,
     * customers.billing_entity_id) against the filter.
     */
    private function withBillingEntityIds(Builder $scope): ?Builder
    {
        $billingEntityIds = $this->filters['billing_entity_ids'] ?? null;

        if ($billingEntityIds === null || $billingEntityIds === []) {
            return null;
        }

        $placeholders = implode(', ', array_fill(0, count($billingEntityIds), '?'));

        // select(wallets.*) — the join must not clobber the wallet
        // attributes with the customers columns (duplicate column names).
        return $scope->select('wallets.*')
            ->join('customers', 'customers.id', '=', 'wallets.customer_id')
            ->whereRaw(
                "coalesce(wallets.billing_entity_id, customers.billing_entity_id) in ({$placeholders})",
                array_values($billingEntityIds),
            );
    }

    /** Rails: `organization.customers.where(external_id: filters.external_customer_id)`. */
    private function customer(): Builder
    {
        return Customer::query()
            ->where('organization_id', $this->organization->id)
            ->where('external_id', $this->filters['external_customer_id'] ?? null);
    }

    /**
     * Rails: `paginate` + kaminari — page/limit; nil (or blank) values fall
     * back to the defaults (page 1, `per_page` param else 100).
     */
    private function paginate(Builder $scope): LengthAwarePaginator
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
