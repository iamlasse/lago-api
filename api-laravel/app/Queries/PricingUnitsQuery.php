<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\PricingUnit;
use App\Models\Organization;
use App\Services\BaseResult;
use App\GraphQL\Support\Page;
use App\Services\BaseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Port of Rails' PricingUnitsQuery (app/queries/pricing_units_query.rb):
 * the organization's pricing units, narrowed by the search term, kaminari
 * paginated, then consistently ordered.
 */
class PricingUnitsQuery extends BaseService
{
    public function __construct(
        private readonly Organization $organization,
        private readonly array $pagination = ['page' => null, 'limit' => null],
        private readonly ?string $searchTerm = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('pricing_units');

        $result->pricing_units = $this->paginate($this->baseScope());

        return $result;
    }

    /**
     * Rails: `base_scope` — PricingUnit scoped to the organization, narrowed
     * by the search term (ransack m: "or" over name_cont / code_cont, i.e.
     * ILIKE %term% on both columns).
     */
    private function baseScope(): Builder
    {
        $scope = PricingUnit::query()->where('organization_id', $this->organization->id);

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
     * Rails: `paginate` then `apply_consistent_ordering(default_order:
     * {name: :asc, created_at: :desc})` — the scopes apply lazily, so
     * pagination happens before the ordering here too.
     */
    private function paginate(Builder $scope): LengthAwarePaginator
    {
        // Rails: `scope.page(pagination.page).per(pagination.limit)` — nil
        // page/limit fall back to the kaminari defaults (page 1, 25 per page)
        // through Page::normalize.
        [$page, $perPage] = Page::normalizePageAndLimit(
            is_numeric($this->pagination['page'] ?? null) ? (int) $this->pagination['page'] : null,
            is_numeric($this->pagination['limit'] ?? null) ? (int) $this->pagination['limit'] : null,
        );

        return $scope
            ->orderBy('name')
            ->latest()
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }
}
