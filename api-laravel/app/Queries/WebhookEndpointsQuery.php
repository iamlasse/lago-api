<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\WebhookEndpoint;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Port of Rails' WebhookEndpointsQuery (app/queries/webhook_endpoints_query.rb).
 *
 * Not ported (not reachable from the REST controllers):
 * - TODO(port): search_term free-text search (ransack webhook_url OR).
 */
class WebhookEndpointsQuery extends BaseService
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
        $result = static::makeResult('webhook_endpoints');

        $endpoints = WebhookEndpoint::query()
            ->where('organization_id', $this->organization->id);

        // Rails: paginate + apply_consistent_ordering(default_order:
        // {webhook_url: :asc, created_at: :desc}) + the id tiebreak —
        // Laravel orders the builder before paginating (same result set).
        $result->webhook_endpoints = $this->paginate(
            $endpoints->orderBy('webhook_url')->latest()->orderBy('id'),
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
