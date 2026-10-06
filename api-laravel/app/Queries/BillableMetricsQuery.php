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
 * The REST index only passes organization + pagination; the GraphQL
 * `billableMetrics` resolver additionally passes the search term and the
 * recurring / aggregation_types / plan_id filters.
 */
class BillableMetricsQuery extends BaseService
{
    /** Kaminari's default per page, used when the limit param is nil. */
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
        $result = static::makeResult('billable_metrics');

        $metrics = BillableMetric::query()
            ->where('organization_id', $this->organization->id);

        // Rails: ransack m: "or", name_cont/code_cont.
        if (($term = (string) $this->searchTerm) !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
            $metrics->where(function ($query) use ($escaped): void {
                $query->where('name', 'ILIKE', '%'.$escaped.'%')
                    ->orWhere('code', 'ILIKE', '%'.$escaped.'%');
            });
        }

        // Rails: paginate + apply_consistent_ordering (created_at desc, id
        // asc) — ordering is applied after pagination.
        $metrics->latest()->orderBy('id');

        // Rails: with_recurring.
        if (($this->filters['recurring'] ?? null) !== null) {
            $metrics->where('recurring', $this->filters['recurring']);
        }

        // Rails: with_aggregation_type.
        if (($aggregationTypes = $this->filters['aggregation_types'] ?? null) !== null
            && $aggregationTypes !== []) {
            $metrics->whereIn('aggregation_type', $aggregationTypes);
        }

        // Rails: with_plan — distinct metrics that carry a charge of the plan.
        if (($planId = $this->filters['plan_id'] ?? null) !== null) {
            $metrics->where('id', function ($query) use ($planId): void {
                $query->select('billable_metric_id')
                    ->from('charges')
                    ->where('plan_id', $planId);
            })->distinct();
        }

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

        return $scope->paginate($perPage, ['*'], 'page', $page);
    }
}
