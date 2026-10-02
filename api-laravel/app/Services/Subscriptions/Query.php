<?php

declare(strict_types=1);

namespace App\Services\Subscriptions;

use App\Models\Plan;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Subscription;
use App\Services\BaseResult;
use App\GraphQL\Support\Page;
use App\Services\BaseService;
use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

use function is_array;

/**
 * Port of Rails' SubscriptionsQuery (app/queries/subscriptions_query.rb) —
 * the query object behind the `subscriptions` GraphQL resolver.
 *
 * Faithful to the Rails filter semantics:
 * - the search term narrows the scope to the union of the searchable
 *   branches (subscription name/external_id, plan name/code, subscription id
 *   when the term is a UUID, customer name/firstname/lastname/external_id/
 *   email) and is skipped entirely when an `external_id` filter is present;
 * - `exclude_next_subscriptions` (always true from the GraphQL resolver, so
 *   the FE's next subscription does not show up twice) keeps subscriptions
 *   without a previous one, plus the Rails escape hatches: previous
 *   subscriptions already terminated, previous subscriptions whose status
 *   falls outside the requested statuses, and canceled subscriptions;
 * - consistent ordering: subscription_at DESC NULLS LAST, created_at DESC,
 *   then id ASC (Rails composes the order after pagination — with offset
 *   pagination the final SQL is identical, so here the order is applied
 *   before the page cut);
 * - `overriden` is the legacy typo kept for backward compatibility (the
 *   GraphQL argument name).
 */
class Query extends BaseService
{
    private const UUID_REGEX = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

    /**
     * @param  array<string, mixed>  $filters  Rails-shaped (snake_case) filters
     * @param  array{page: ?int, limit: ?int}  $pagination
     */
    public function __construct(
        private readonly Organization $organization,
        private readonly array $filters = [],
        private readonly array $pagination = ['page' => null, 'limit' => null],
        private readonly ?string $searchTerm = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('subscriptions');

        $subscriptions = $this->baseScope();

        if ($this->filters['exclude_next_subscriptions'] ?? false) {
            $subscriptions = $this->withExcludedNextSubscriptions($subscriptions);
        }

        $statuses = $this->filteredStatuses();
        if ($statuses !== null) {
            $subscriptions = $subscriptions->whereIn('subscriptions.status', $statuses);
        }

        $subscriptions = $this->applyConsistentOrdering($subscriptions);

        $subscriptions = $this->withBillingEntityIds($subscriptions) ?? $subscriptions;
        $subscriptions = $this->withExternalId($subscriptions) ?? $subscriptions;
        $subscriptions = $this->withExternalCustomer($subscriptions) ?? $subscriptions;
        $subscriptions = $this->withPlanCode($subscriptions) ?? $subscriptions;
        $subscriptions = $this->withOverridden($subscriptions) ?? $subscriptions;
        $subscriptions = $this->withCurrency($subscriptions) ?? $subscriptions;

        // Rails: the GraphQL resolver preloads {next_subscriptions: :plan} and
        // {customer: :billing_entity} on the paginated result.
        $result->subscriptions = $this->paginate(
            $subscriptions->with(['nextSubscriptions.plan', 'customer.billingEntity']),
        );

        return $result;
    }

    /**
     * Rails: `base_scope` — organization scope, narrowed by the search term
     * (unless an explicit external_id filter is given).
     */
    private function baseScope(): Builder
    {
        $scope = Subscription::query()->where('subscriptions.organization_id', $this->organization->id);

        $searchTerm = (string) ($this->searchTerm ?? '');

        if ($searchTerm === '' || $this->present($this->filters['external_id'] ?? null)) {
            return $scope;
        }

        return $scope->whereIn('subscriptions.id', $this->matchingIdsBySearch($searchTerm));
    }

    /**
     * Rails: `matching_ids_by_search` — a UNION of single-table branches. The
     * union here selects the same rows as an OR over the searchable columns.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function matchingIdsBySearch(string $searchTerm): \Illuminate\Support\Collection
    {
        $escapedTerm = '%'.$this->escapeLike($searchTerm).'%';

        return Subscription::query()
            ->where('organization_id', $this->organization->id)
            ->where(function (Builder $query) use ($escapedTerm, $searchTerm): void {
                $query->where('name', 'ILIKE', $escapedTerm)
                    ->orWhere('external_id', 'ILIKE', $escapedTerm)
                    ->orWhereIn('plan_id', $this->matchingPlanIds($escapedTerm));

                if (preg_match(self::UUID_REGEX, $searchTerm) === 1) {
                    $query->orWhere('id', $searchTerm);
                }

                if ($this->searchCustomers()) {
                    $query->orWhereIn('customer_id', $this->matchingCustomerIds($escapedTerm));
                }
            })
            ->pluck('id');
    }

    /** Rails: `matching_plan_ids` — plans.name ILIKE term OR plans.code ILIKE term. */
    private function matchingPlanIds(string $escapedTerm): \Illuminate\Support\Collection
    {
        return Plan::query()
            ->where('organization_id', $this->organization->id)
            ->where(function (Builder $query) use ($escapedTerm): void {
                $query->where('name', 'ILIKE', $escapedTerm)
                    ->orWhere('code', 'ILIKE', $escapedTerm);
            })
            ->pluck('id');
    }

    /** Rails: `matching_customer_ids` — the customer searchable columns. */
    private function matchingCustomerIds(string $escapedTerm): \Illuminate\Support\Collection
    {
        return Customer::query()
            ->where('organization_id', $this->organization->id)
            ->where(function (Builder $query) use ($escapedTerm): void {
                $query->where('name', 'ILIKE', $escapedTerm)
                    ->orWhere('firstname', 'ILIKE', $escapedTerm)
                    ->orWhere('lastname', 'ILIKE', $escapedTerm)
                    ->orWhere('external_id', 'ILIKE', $escapedTerm)
                    ->orWhere('email', 'ILIKE', $escapedTerm);
            })
            ->pluck('id');
    }

    /** Rails: `search_customers?` — only when no external_customer_id filter. */
    private function searchCustomers(): bool
    {
        return ! $this->present($this->filters['external_customer_id'] ?? null);
    }

    /**
     * Rails: `with_excluded_next_subscriptions` — drop subscriptions that are
     * the previous of a listed one, with the FE-driven escape hatches.
     */
    private function withExcludedNextSubscriptions(Builder $scope): Builder
    {
        $terminated = SubscriptionStatus::Terminated->value;
        $canceled = SubscriptionStatus::Canceled->value;
        $statuses = $this->filteredStatuses();

        return $scope
            ->addSelect('subscriptions.*')
            ->leftJoin('subscriptions as prev_subscriptions', 'subscriptions.previous_subscription_id', '=', 'prev_subscriptions.id')
            ->where(function (Builder $query) use ($terminated, $canceled, $statuses): void {
                $query->whereNull('subscriptions.previous_subscription_id')
                    ->orWhere('prev_subscriptions.status', $terminated)
                    ->orWhere('subscriptions.status', $canceled);

                // Rails: when a status filter is set, keep a previous
                // subscription whose status is outside the filter (its next
                // subscription matches what the user asked for).
                if ($statuses !== null && $statuses !== []) {
                    $query->orWhere(function (Builder $pair) use ($statuses): void {
                        $pair->whereNotIn('prev_subscriptions.status', $statuses)
                            ->whereIn('subscriptions.status', $statuses);
                    });
                }
            });
    }

    /**
     * Rails: `filtered_statuses` + `valid_status?` — the status names mapped
     * to their stored integers, or null when the filter is absent or carries
     * an unknown status (Rails skips the filter entirely then).
     *
     * @return list<int>|null
     */
    private function filteredStatuses(): ?array
    {
        $status = $this->filters['status'] ?? null;

        if (! is_array($status) || $status === []) {
            return null;
        }

        $values = [];
        foreach ($status as $name) {
            $value = SubscriptionStatus::fromOption($name);

            if ($value === null) {
                return null;
            }

            $values[] = $value;
        }

        return $values;
    }

    /** Rails: apply_consistent_ordering(default_order: subscription_at DESC NULLS LAST, created_at DESC). */
    private function applyConsistentOrdering(Builder $scope): Builder
    {
        return $scope
            ->orderByRaw('subscriptions.subscription_at desc nulls last')
            ->orderByRaw('subscriptions.created_at desc')
            ->orderBy('id');
    }

    private function withBillingEntityIds(Builder $scope): ?Builder
    {
        $billingEntityIds = $this->filters['billing_entity_ids'] ?? null;

        if (! $this->present($billingEntityIds)) {
            return null;
        }

        return $scope
            ->addSelect('subscriptions.*')
            ->leftJoin('customers', 'customers.id', '=', 'subscriptions.customer_id')
            ->where(function (Builder $query) use ($billingEntityIds): void {
                $query->whereIn('subscriptions.billing_entity_id', $billingEntityIds)
                    ->orWhere(function (Builder $pair) use ($billingEntityIds): void {
                        $pair->whereNull('subscriptions.billing_entity_id')
                            ->whereIn('customers.billing_entity_id', $billingEntityIds);
                    });
            });
    }

    private function withExternalId(Builder $scope): ?Builder
    {
        $externalId = $this->filters['external_id'] ?? null;

        if (! $this->present($externalId)) {
            return null;
        }

        return $scope->where('subscriptions.external_id', $externalId);
    }

    private function withExternalCustomer(Builder $scope): ?Builder
    {
        $externalCustomerId = $this->filters['external_customer_id'] ?? null;

        if (! $this->present($externalCustomerId)) {
            return null;
        }

        return $scope->whereIn(
            'subscriptions.customer_id',
            Customer::query()->where('external_id', $externalCustomerId)->select('id'),
        );
    }

    private function withPlanCode(Builder $scope): ?Builder
    {
        $planCode = $this->filters['plan_code'] ?? null;

        if (! $this->present($planCode)) {
            return null;
        }

        return $scope
            ->addSelect('subscriptions.*')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->where('plans.code', $planCode);
    }

    /**
     * Rails: `with_overridden` — the overridden/overriden legacy-typo pair;
     * the GraphQL wire only carries `overriden`.
     */
    private function withOverridden(Builder $scope): ?Builder
    {
        $overridden = array_key_exists('overridden', $this->filters)
            ? $this->filters['overridden']
            : ($this->filters['overriden'] ?? null);

        if ($overridden === null) {
            return null;
        }

        $scope = $scope->addSelect('subscriptions.*')->join('plans', 'plans.id', '=', 'subscriptions.plan_id');

        if ($this->booleanCast($overridden)) {
            return $scope->whereNotNull('plans.parent_id');
        }

        return $scope->whereNull('plans.parent_id');
    }

    private function withCurrency(Builder $scope): ?Builder
    {
        $currency = $this->filters['currency'] ?? null;

        if (! $this->present($currency)) {
            return null;
        }

        return $scope
            ->addSelect('subscriptions.*')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->where('plans.amount_currency', $currency);
    }

    /** Rails: `paginate` — kaminari page/per through the shared defaults. */
    private function paginate(Builder $scope): LengthAwarePaginator
    {
        [$page, $limit] = Page::normalizePageAndLimit(
            $this->pagination['page'] ?? null,
            $this->pagination['limit'] ?? null,
        );

        return $scope->paginate(perPage: $limit, page: $page);
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

    /** Port of `ActiveRecord::Sanitization.sanitize_sql_like`. */
    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
    }
}
