<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Tax;
use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Port of Rails' TaxesQuery (app/queries/taxes_query.rb).
 *
 * The REST index only passes organization + pagination.
 *
 * Not ported (not reachable from the REST controllers — GraphQL-only):
 * - TODO(port): the auto_generated / applied_to_organization filters;
 * - TODO(port): the `order` param (name | rate; default name) — the REST
 *   callers pass no order, so the default "name" ASC + the consistent
 *   ordering tiebreak is applied unconditionally;
 * - TODO(port): search_term free-text search (ransack name/code OR).
 */
class TaxesQuery extends BaseService
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
        $result = static::makeResult('taxes');

        // Rails: base_scope.preload(:billing_entities) — billingEntities()
        // is a hand-rolled join (no relation yet), so there is nothing to
        // eager-load.
        $taxes = Tax::query()
            ->where('organization_id', $this->organization->id);

        // Rails: paginate, then order(order) (default "name" ASC), then
        // apply_consistent_ordering (created_at desc, id asc) — Laravel
        // orders the builder before paginating (same result set).
        $result->taxes = $this->paginate(
            $taxes->orderBy('name')->latest()->orderBy('id'),
        );

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
