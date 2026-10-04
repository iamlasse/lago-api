<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Feature;
use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Port of Rails' Entitlement::FeaturesQuery
 * (app/queries/entitlement/features_query.rb) — the feature index with the
 * ransack OR free-text search (name / code / description contains the term)
 * and the consistent ordering tiebreak.
 */
class FeaturesQuery extends BaseService
{
    /** Kaminari's default per page, used when the limit param is nil. */
    private const DEFAULT_PER_PAGE = 100;

    public function __construct(
        private readonly Organization $organization,
        private readonly array $pagination = ['page' => null, 'limit' => null],
        private readonly ?string $searchTerm = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('features');

        $features = Feature::query()
            ->where('organization_id', $this->organization->id);

        if (($term = (string) ($this->searchTerm ?? '')) !== '') {
            $features->where(function ($query) use ($term): void {
                $like = '%'.$term.'%';

                // Ransack's `cont` predicate — case-sensitive LIKE.
                $query->where('name', 'LIKE', $like)
                    ->orWhere('code', 'LIKE', $like)
                    ->orWhere('description', 'LIKE', $like);
            });
        }

        // Rails: paginate, then apply_consistent_ordering (created_at desc,
        // id asc).
        $result->features = $this->paginate($features->latest()->orderBy('id'));

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

        return $scope->with('privileges')->paginate($perPage, ['*'], 'page', $page);
    }
}
