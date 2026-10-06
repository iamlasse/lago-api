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
 * The REST index only passes organization + pagination; the GraphQL
 * `taxes` resolver additionally passes the search term, the order and the
 * auto_generated / applied_to_organization filters.
 */
class TaxesQuery extends BaseService
{
    /** Kaminari's default per page, used when the limit param is nil. */
    private const DEFAULT_PER_PAGE = 100;

    public function __construct(
        private readonly Organization $organization,
        private readonly ?string $searchTerm = null,
        private readonly array $pagination = ['page' => null, 'limit' => null],
        private readonly array $filters = [],
        private readonly ?string $order = null,
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

        // Rails: ransack m: "or", name_cont/code_cont.
        if (($term = (string) $this->searchTerm) !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
            $taxes->where(function ($query) use ($escaped): void {
                $query->where('name', 'ILIKE', '%'.$escaped.'%')
                    ->orWhere('code', 'ILIKE', '%'.$escaped.'%');
            });
        }

        // Rails: with_auto_generated — only when the filter is present.
        if (($this->filters['auto_generated'] ?? null) !== null) {
            $taxes->where('auto_generated', $this->filters['auto_generated']);
        }

        // Rails: with_applied_to_organization — taxes applied to the
        // organization's default billing entity; `false` means NOT applied.
        if (array_key_exists('applied_to_organization', $this->filters)
            && $this->filters['applied_to_organization'] !== null) {
            $applied = $this->filters['applied_to_organization'];

            $defaultBillingEntity = $this->organization->defaultBillingEntity;

            $appliedIds = $defaultBillingEntity === null
                ? []
                : \Illuminate\Support\Facades\DB::table('billing_entities_taxes')
                    ->where('billing_entity_id', $defaultBillingEntity->id)
                    ->pluck('tax_id')
                    ->all();

            if ($applied) {
                $taxes->whereIn('id', $appliedIds);
            } else {
                $taxes->whereNotIn('id', $appliedIds);
            }
        }

        // Rails: paginate, then order(order) (default "name" ASC), then
        // apply_consistent_ordering (created_at desc, id asc) — Laravel
        // orders the builder before paginating (same result set).
        $order = in_array($this->order, Tax::ORDERS, true) ? $this->order : 'name';

        $result->taxes = $this->paginate(
            $taxes->orderBy($order)->latest()->orderBy('id'),
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
