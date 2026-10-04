<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\AddOn;
use App\Services\BaseResult;
use App\GraphQL\Support\Page;
use App\Services\BaseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Port of Rails' AddOnsQuery (app/queries/add_ons_query.rb).
 *
 * The REST index passes organization + pagination only; the GraphQL `addOns`
 * resolver adds the free-text search term (ransack `name_cont OR code_cont`
 * — an ILIKE '%term%' on both columns).
 */
class AddOnsQuery extends BaseService
{
    public function __construct(
        private readonly object $organization,
        private readonly array $pagination = ['page' => null, 'limit' => null],
        private readonly ?string $searchTerm = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('add_ons');

        // Rails: AddOn.kept.where(organization:).ransack(search_params).result
        // (AddOn's default_scope keeps discarded rows out).
        $addOns = AddOn::query()
            ->where('organization_id', $this->organization->id);

        $addOns = $this->withSearchTerm($addOns);

        $result->add_ons = $this->paginate(
            $addOns
                // Rails: apply_consistent_ordering (created_at desc, id asc).
                ->latest('add_ons.created_at')
                ->orderBy('add_ons.id'),
        );

        return $result;
    }

    /**
     * Rails: the ransack `m: or` search — name_cont OR code_cont. Blank
     * search terms return the scope untouched.
     */
    private function withSearchTerm(Builder $scope): Builder
    {
        $term = $this->searchTerm;

        if ($term === null || mb_trim($term) === '') {
            return $scope;
        }

        $like = '%'.mb_strtolower(mb_trim($term)).'%';

        return $scope->where(function (Builder $query) use ($like): void {
            $query->whereRaw('LOWER(add_ons.name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(add_ons.code) LIKE ?', [$like]);
        });
    }

    /**
     * Rails: `paginate` — kaminari page/per; nil page/limit fall back to
     * the kaminari defaults (page 1, 25 per page) through Page::normalize.
     */
    private function paginate(Builder $scope): LengthAwarePaginator
    {
        [$page, $limit] = Page::normalizePageAndLimit(
            is_numeric($this->pagination['page'] ?? null) ? (int) $this->pagination['page'] : null,
            is_numeric($this->pagination['limit'] ?? null) ? (int) $this->pagination['limit'] : null,
        );

        return $scope->paginate(perPage: $limit, page: $page);
    }
}
