<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Plan;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Subscription;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Port of Rails' SubscriptionsQuery (app/queries/subscriptions_query.rb).
 *
 * The REST index always scopes by organization (Rails also accepts a
 * `customer` filter instead — a GraphQL surface concern).
 *
 * Not ported (not reachable from the REST controllers):
 * - TODO(port): search_term free-text search (the UNION of ILIKE branches
 *   over subscriptions/plans/customers) — the GraphQL subscriptions query
 *   is the only caller;
 * - TODO(port): exclude_next_subscriptions (the FE next-subscription
 *   dedup join) — same GraphQL-only caller.
 */
class SubscriptionsQuery extends BaseService
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
        $result = static::makeResult('subscriptions');

        $subscriptions = $this->baseScope();

        $statuses = $this->filteredStatuses();
        if ($statuses !== null) {
            $subscriptions = $subscriptions->whereIn('status', $statuses);
        }

        // Rails: apply_consistent_ordering with the default order (a
        // subscription_at DESC NULLS LAST, created_at DESC tiebreak by id).
        $subscriptions = $subscriptions
            ->orderByRaw('subscriptions.subscription_at desc nulls last')
            ->latest('subscriptions.created_at')
            ->orderBy('subscriptions.id');

        $subscriptions = $this->withBillingEntityIds($subscriptions) ?? $subscriptions;
        $subscriptions = $this->withExternalId($subscriptions) ?? $subscriptions;
        $subscriptions = $this->withExternalCustomer($subscriptions) ?? $subscriptions;
        $subscriptions = $this->withPlanCode($subscriptions) ?? $subscriptions;
        $subscriptions = $this->withOverridden($subscriptions) ?? $subscriptions;
        $subscriptions = $this->withCurrency($subscriptions) ?? $subscriptions;

        $result->subscriptions = $this->paginate($subscriptions);

        return $result;
    }

    /**
     * Rails: `base_scope` — the organization scope, eager-loading the two
     * relations every serialized row touches (customer + plan).
     */
    private function baseScope(): Builder
    {
        return Subscription::query()
            ->where('organization_id', $this->organization->id)
            ->with(['customer', 'plan']);
    }

    /**
     * Rails: `filtered_statuses` + `valid_status?` — the status filter only
     * applies when every given name is a known subscription status; an
     * invalid name silently drops the whole filter.
     *
     * @return list<int>|null
     */
    private function filteredStatuses(): ?array
    {
        $status = $this->filters['status'] ?? null;

        if (! is_array($status) || $status === []) {
            return null;
        }

        $statuses = [];
        foreach ($status as $name) {
            $value = SubscriptionStatus::fromOption($name);

            if ($value === null) {
                return null;
            }

            $statuses[] = $value;
        }

        return $statuses;
    }

    private function withExternalId(Builder $scope): ?Builder
    {
        $externalId = $this->filters['external_id'] ?? null;

        if (! $this->present($externalId)) {
            return null;
        }

        return $scope->where('external_id', $externalId);
    }

    /**
     * Rails: `with_external_customer` — deliberately not scoped to the
     * organization (same query shape as the Rails filter).
     */
    private function withExternalCustomer(Builder $scope): ?Builder
    {
        $externalCustomerId = $this->filters['external_customer_id'] ?? null;

        if (! $this->present($externalCustomerId)) {
            return null;
        }

        return $scope->whereIn('customer_id', Customer::query()
            ->where('external_id', $externalCustomerId)
            ->select('id'));
    }

    private function withPlanCode(Builder $scope): ?Builder
    {
        $planCode = $this->filters['plan_code'] ?? null;

        if (! $this->present($planCode)) {
            return null;
        }

        return $scope->whereIn('subscriptions.plan_id', Plan::query()
            ->where('code', $planCode)
            ->select('id'));
    }

    /**
     * Rails: `overridden_filter` — `overridden` wins over the legacy
     * `overriden` typo (kept for backward compatibility); a boolean cast of
     * false keeps only parent plans, true only overridden (child) plans.
     */
    private function withOverridden(Builder $scope): ?Builder
    {
        $filter = ($this->filters['overridden'] ?? null) ?? ($this->filters['overriden'] ?? null);

        if ($filter === null) {
            return null;
        }

        if ($this->booleanCast($filter)) {
            return $scope->whereIn('subscriptions.plan_id', Plan::query()
                ->whereNotNull('parent_id')
                ->select('id'));
        }

        return $scope->whereIn('subscriptions.plan_id', Plan::query()
            ->whereNull('parent_id')
            ->select('id'));
    }

    private function withCurrency(Builder $scope): ?Builder
    {
        $currency = $this->filters['currency'] ?? null;

        if (! $this->present($currency)) {
            return null;
        }

        return $scope->whereIn('subscriptions.plan_id', Plan::query()
            ->where('amount_currency', $currency)
            ->select('id'));
    }

    /**
     * Rails: `with_billing_entity_ids` — the subscription's own stamp wins;
     * rows without one fall back to their customer's billing entity.
     * (Rails expresses the fallback with a customers JOIN; the same OR is
     * written as a subquery so repeated filters cannot double-join.)
     */
    private function withBillingEntityIds(Builder $scope): ?Builder
    {
        $billingEntityIds = $this->filters['billing_entity_ids'] ?? null;

        if (! $this->present($billingEntityIds) || ! is_array($billingEntityIds)) {
            return null;
        }

        return $scope->whereRaw(
            '(subscriptions.billing_entity_id in (?) or subscriptions.customer_id in (select id from customers where billing_entity_id in (?)))',
            [$billingEntityIds, $billingEntityIds],
        );
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

    /** Ruby `present?` — non-null and non-empty. */
    private function present(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === []) {
            return false;
        }

        return true;
    }

    /** Port of ActiveModel::Type::Boolean#cast (the values that matter here). */
    private function booleanCast(mixed $value): bool
    {
        return in_array($value, ['true', 'TRUE', 't', 'T', '1', 1, true], true);
    }
}
