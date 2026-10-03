<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\BillableMetric;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Port of Rails' BillableMetricsQuery (app/queries/billable_metrics_query.rb).
 *
 * The REST index only passes organization + pagination.
 *
 * Not ported (not reachable from the REST controllers — GraphQL-only):
 * - TODO(port): the Filters contract (recurring / aggregation_types /
 *   plan_id, Queries::BillableMetricsQueryFiltersContract);
 * - TODO(port): search_term free-text search (ransack name/code OR);
 * - TODO(port): the charges join for the plan_id filter.
 */
class BillableMetricsQuery extends BaseService
{
    /** Kaminari's default per page, used when the limit param is nil. */
    private const DEFAULT_PER_PAGE = 100;

    public function __construct(
        private readonly Organization $organization,
        private readonly array $pagination = ['page' => null, 'limit' => null],
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('billable_metrics');

        $metrics = BillableMetric::query()
            ->where('organization_id', $this->organization->id);

        // Rails: paginate + apply_consistent_ordering (created_at desc, id
        // asc) — ordering is applied after pagination.
        $result->billable_metrics = $this->paginate($metrics);

        return $result;
    }

    /**
     * Rails: `paginate` + kaminari — page/limit; nil (or blank) values fall
     * back to the defaults (page 1, `per_page` param else 100).
     */
    private function paginate(\Illuminate\Database\Eloquent\Builder $scope): LengthAwarePaginator
    {
        $pageParam = $this->pagination['page'] ?? null;
        $limitParam = $this->pagination['limit'] ?? null;

        $page = is_numeric((string) $pageParam) && (string) $pageParam !== '' ? max(1, (int) $pageParam) : 1;

        $perPage = self::DEFAULT_PER_PAGE;
        if ($limitParam !== null && $limitParam !== '') {
            $perPage = max(1, (int) $limitParam);
        }

        return $scope
            ->latest()
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }
}
