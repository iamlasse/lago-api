<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Port of Rails' InvitesQuery (app/queries/invites_query.rb) — the pending
 * invites of an organization, filtered by role ids (resolved to codes
 * first: invites carry role codes in an array column) and searched by email.
 */
class InvitesQuery extends BaseService
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
        $result = static::makeResult('invites');

        // Rails: organization.invites.pending — the Organization model is
        // read-only in this slice, so the org scope is applied directly.
        $invites = \App\Models\Invite::query()
            ->where('organization_id', $this->organization->id)
            ->pending();

        $searchTerm = $this->searchTerm;
        if ($searchTerm !== null && $searchTerm !== '') {
            $escaped = str_replace('\\', '\\\\', (string) $searchTerm);
            $escaped = str_replace(['%', '_'], ['\%', '\_'], $escaped);
            $invites->where('invites.email', 'ILIKE', "%{$escaped}%");
        }

        $invites = $this->withRoleIds($invites);

        $paginator = $this->paginate($invites);

        $result->invites = $paginator;

        return $result;
    }

    /**
     * Rails: with_role_ids — codes resolved via
     * Role.with_organization(org.id).where(id:), then a Postgres array
     * overlap (`invites.roles && ARRAY[?]::varchar[]`).
     */
    private function withRoleIds(Builder $invites): Builder
    {
        $roleIds = $this->filters['role_ids'] ?? null;

        if ($roleIds === null || $roleIds === []) {
            return $invites;
        }

        $codes = \App\Models\Role::query()
            ->where(function ($query): void {
                $query->whereNull('organization_id')
                    ->orWhere('organization_id', $this->organization->id);
            })
            ->whereIn('id', (array) $roleIds)
            ->pluck('code')
            ->all();

        if ($codes === []) {
            return $invites->whereRaw('1 = 0');
        }

        return $invites->whereRaw(
            'invites.roles && ?::varchar[]',
            ['{'.implode(',', array_map(
                fn (string $code): string => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $code).'"',
                $codes,
            )).'}'],
        );
    }

    /**
     * Rails: paginate + apply_consistent_ordering (kaminari page/per,
     * created_at ASC, id ASC tiebreak — BaseQuery's consistent ordering).
     */
    private function paginate(Builder $query): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $page = max(1, (int) ($this->pagination['page'] ?? 1));
        $limit = max(1, (int) ($this->pagination['limit'] ?? \App\GraphQL\Support\Page::DEFAULT_LIMIT));

        return $query->orderByDesc('created_at')->orderBy('id')->paginate(perPage: $limit, page: $page);
    }
}
