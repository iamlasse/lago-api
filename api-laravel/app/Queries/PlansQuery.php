<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Plan;
use App\Models\Organization;
use App\Services\BaseResult;
use App\GraphQL\Support\Page;
use App\Services\BaseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Port of Rails' PlansQuery (app/queries/plans_query.rb).
 *
 * Deleted plans are kept for history but should not clutter the top of the
 * list, so they are grouped after the active ones. Both groups stay sorted
 * by name to remain easy to scan.
 */
class PlansQuery extends BaseService
{
    public function __construct(
        private readonly Organization $organization,
        private readonly array $pagination = ['page' => null, 'limit' => null],
        private readonly array $filters = [],
        private readonly ?string $searchTerm = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('plans');

        $result->plans = $this->paginate($this->baseScope());

        return $result;
    }

    /**
     * Rails: `base_scope` — Plan.parents scoped to the organization,
     * narrowed by the search term (ransack m: "or" over name_cont /
     * code_cont, i.e. ILIKE %term% on both columns).
     */
    private function baseScope(): Builder
    {
        $scope = Plan::query()->parents()->where('organization_id', $this->organization->id);

        $searchTerm = (string) ($this->searchTerm ?? '');

        if ($searchTerm === '') {
            return $scope;
        }

        $escapedTerm = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $searchTerm).'%';

        return $scope->where(function (Builder $query) use ($escapedTerm): void {
            $query->where('name', 'ILIKE', $escapedTerm)
                ->orWhere('code', 'ILIKE', $escapedTerm);
        });
    }

    /**
     * Rails: `paginate` + the filter application + `apply_consistent_ordering`
     * (the scopes apply lazily, so ordering here mirrors the Rails order of
     * operations: filters, then kaminari pagination, then ordering).
     */
    private function paginate(Builder $scope): LengthAwarePaginator
    {
        // exclude_pending_deletion — applied unless filters.include_pending_deletion.
        if (! ($this->filters['include_pending_deletion'] ?? false)) {
            $scope = $scope->where('pending_deletion', false);
        }

        // plans.with_discarded when filters.with_deleted.
        if ($this->filters['with_deleted'] ?? false) {
            $scope = $scope->withTrashed();
        }

        // Rails: `scope.page(pagination.page).per(pagination.limit)` — nil
        // page/limit fall back to the kaminari defaults (page 1, 25 per page)
        // through Page::normalize.
        [$page, $perPage] = Page::normalizePageAndLimit(
            is_numeric($this->pagination['page'] ?? null) ? (int) $this->pagination['page'] : null,
            is_numeric($this->pagination['limit'] ?? null) ? (int) $this->pagination['limit'] : null,
        );

        return $scope
            ->orderByRaw('plans.deleted_at is not null asc')
            ->orderBy('name')
            ->latest()
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }
}
