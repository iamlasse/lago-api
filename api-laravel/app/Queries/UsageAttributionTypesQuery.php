<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\UsageAttributionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Port of Rails' UsageAttributionTypesQuery — the GraphQL usage_attribution_types
 * index: a search term over code / name, the role and roots filters, and
 * kaminari pagination with the consistent ordering.
 *
 * Rails: only_roots — a root is a type without a parent, or whose parent is
 * not one of the organization's (kept) types — the whole subtree then
 * travels through `children`, so paginating the roots keeps each subtree
 * intact.
 */
class UsageAttributionTypesQuery extends BaseService
{
    /** Kaminari's default per page, used when the limit param is nil. */
    private const DEFAULT_PER_PAGE = 100;

    public function __construct(
        private readonly Organization $organization,
        private readonly ?string $searchTerm = null,
        private readonly array $pagination = ['page' => null, 'limit' => null],
        private readonly array $filters = ['role' => null, 'roots' => null],
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('usage_attribution_types');

        $types = UsageAttributionType::query()
            ->where('organization_id', $this->organization->id)
            ->with('parent');

        // Rails: ransack m: "or", code_cont/name_cont.
        if (($term = (string) $this->searchTerm) !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
            $types->where(function (Builder $query) use ($escaped): void {
                $query->where('code', 'ILIKE', '%'.$escaped.'%')
                    ->orWhere('name', 'ILIKE', '%'.$escaped.'%');
            });
        }

        if (($this->filters['role'] ?? null) !== null) {
            // The role column is a native pg enum — it binds its wire name.
            $role = $this->filters['role'];

            $types->where('role', in_array((string) $role, UsageAttributionType::ROLES, true)
                ? (string) $role
                : (UsageAttributionType::ROLES[(int) $role] ?? 'hierarchical'));
        }

        if ($this->filters['roots'] ?? null) {
            $types->where(function (Builder $query): void {
                $query->whereNull('usage_attribution_types.parent_id')
                    ->orWhereNotIn(
                        'usage_attribution_types.parent_id',
                        UsageAttributionType::query()
                            ->where('organization_id', $this->organization->id)
                            ->select('id'),
                    );
            });
        }

        // Rails: paginate, then apply_consistent_ordering (created_at desc,
        // id asc) — ordered before paginating (same result set).
        $result->usage_attribution_types = $this->paginate(
            $types->orderBy('code')->latest()->orderBy('id'),
        );

        return $result;
    }

    /** Rails: `paginate` + kaminari — page/limit. */
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
