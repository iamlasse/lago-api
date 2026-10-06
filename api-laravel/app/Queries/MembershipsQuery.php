<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Role;
use App\Models\User;
use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\MembershipRole;
use Illuminate\Database\Eloquent\Builder;

/**
 * Port of Rails' MembershipsQuery (app/queries/memberships_query.rb) — the
 * active memberships, filtered by role ids (via the membership_roles pivot)
 * and searched by user email.
 */
class MembershipsQuery extends BaseService
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
        $result = static::makeResult('memberships');

        $memberships = $this->organization->memberships()->active();

        $searchTerm = $this->searchTerm !== null ? mb_trim($this->searchTerm) : '';

        if ($searchTerm !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $searchTerm);
            $memberships->whereIn('memberships.user_id', User::query()
                ->select('id')
                ->where('users.email', 'ILIKE', "%{$escaped}%"));
        }

        $memberships = $this->withRoleIds($memberships);

        $paginator = $this->paginate($memberships);

        $result->memberships = $paginator;

        return $result;
    }

    /**
     * Rails: with_role_ids — scope.where(id: MembershipRole.joins(:role)
     * .where(organization:, role_id:).select(:membership_id)).
     */
    private function withRoleIds(Builder $memberships): Builder
    {
        $roleIds = $this->filters['role_ids'] ?? null;

        if ($roleIds === null || $roleIds === []) {
            return $memberships;
        }

        return $memberships->whereIn('memberships.id', MembershipRole::query()
            ->whereNull('membership_roles.deleted_at')
            ->join('roles', 'roles.id', '=', 'membership_roles.role_id')
            ->where('membership_roles.organization_id', $this->organization->id)
            ->whereIn('roles.id', (array) $roleIds)
            ->whereNull('roles.deleted_at')
            ->select('membership_roles.membership_id'));
    }

    /**
     * Rails: paginate + apply_consistent_ordering (created_at DESC, id ASC).
     */
    private function paginate(Builder $query): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $page = max(1, (int) ($this->pagination['page'] ?? 1));
        $limit = max(1, (int) ($this->pagination['limit'] ?? \App\GraphQL\Support\Page::DEFAULT_LIMIT));

        return $query->orderByDesc('memberships.created_at')->orderBy('memberships.id')->paginate(perPage: $limit, page: $page);
    }
}
