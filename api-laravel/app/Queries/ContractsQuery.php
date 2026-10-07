<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\ContractRateCard;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Port of Rails' ContractsQuery (app/queries/contracts_query.rb).
 */
class ContractsQuery extends BaseService
{
    private const DEFAULT_PER_PAGE = 100;

    private const UUID_REGEX = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

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
        $result = static::makeResult('contracts');

        $contracts = Contract::query()->where('contracts.organization_id', $this->organization->id);

        $term = $this->searchTerm;
        $externalId = $this->filters['external_id'] ?? null;

        // Rails: the search only runs when no external_id filter narrows the
        // scope already.
        if ($term !== null && $term !== '' && ($externalId === null || $externalId === '')) {
            $contracts->whereIn('contracts.id', $this->matchingIdsBySearch($term));
        }

        if (($this->filters['external_customer_id'] ?? null) !== null) {
            $contracts->where('contracts.customer_id', function ($query): void {
                $query->select('id')
                    ->from('customers')
                    ->where('customers.organization_id', $this->organization->id)
                    ->where('customers.external_id', $this->filters['external_customer_id']);
            });
        }

        if (($this->filters['plan_code'] ?? null) !== null) {
            $contracts->where('contracts.catalog_plan_id', function ($query): void {
                $query->select('id')
                    ->from('catalog_plans')
                    ->where('catalog_plans.organization_id', $this->organization->id)
                    ->where('catalog_plans.code', $this->filters['plan_code']);
            });
        }

        if ($externalId !== null) {
            $contracts->where('contracts.external_id', $externalId);
        }

        // Rails: with_status — the column is a PG enum, so unknown values are
        // dropped (a filter with no valid value matches nothing).
        $statuses = array_values(array_intersect(
            (array) ($this->filters['status'] ?? []),
            array_values(Contract::STATUSES),
        ));

        if ($statuses !== []) {
            $contracts->whereIn('contracts.status', $statuses);
        }

        // Rails: a contract's effective billing entity is its own override,
        // falling back to the customer's — match either side.
        if (($this->filters['billing_entity_ids'] ?? null) !== null && $this->filters['billing_entity_ids'] !== []) {
            $ids = $this->filters['billing_entity_ids'];
            $contracts->join('customers', 'customers.id', '=', 'contracts.customer_id')
                ->where(function ($query) use ($ids): void {
                    $query->whereIn('contracts.billing_entity_id', $ids)
                        ->orWhere(function ($q) use ($ids): void {
                            $q->whereNull('contracts.billing_entity_id')
                                ->whereIn('customers.billing_entity_id', $ids);
                        });
                });
        }

        // Rails: with_rate_overrides — own phases only (a plan-level override
        // is the plan's shared pricing, not a per-contract one).
        $hasRateOverrides = $this->filters['has_rate_overrides'] ?? null;
        if ($hasRateOverrides !== null && $hasRateOverrides !== []) {
            $overridingIds = ContractRateCard::query()
                ->where('contract_rate_cards.organization_id', $this->organization->id)
                ->whereNull('contract_rate_cards.deleted_at')
                ->join('rate_phases', 'rate_phases.contract_rate_card_id', '=', 'contract_rate_cards.id')
                ->whereNull('rate_phases.deleted_at')
                ->whereNotNull('rate_phases.rate_override_id')
                ->select('contract_rate_cards.contract_id');

            $filterValue = filter_var($hasRateOverrides, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($filterValue === true) {
                $contracts->whereIn('contracts.id', $overridingIds);
            } else {
                $contracts->whereNotIn('contracts.id', $overridingIds);
            }
        }

        // Rails: paginate + apply_consistent_ordering.
        $result->contracts = $this->paginate(
            $contracts->latest('contracts.created_at')->orderBy('contracts.id'),
        );

        return $result;
    }

    /**
     * Rails: `matching_ids_by_search` — free-text search as a UNION of
     * single-table branches so each branch can use its own trigram index
     * (contracts name/external_id, catalog plan name/code, contract uuid,
     * customer name/firstname/lastname/external_id/email).
     *
     * @return \Laravel\Scout\Builder|\Illuminate\Support\Collection
     */
    private function matchingIdsBySearch(string $term): \Illuminate\Support\Collection
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
        $like = '%'.$escaped.'%';

        $branches = [];

        $base = fn () => Contract::query()->where('contracts.organization_id', $this->organization->id);

        $branches[] = $base()->where('contracts.name', 'ILIKE', $like)->select('contracts.id');
        $branches[] = $base()->where('contracts.external_id', 'ILIKE', $like)->select('contracts.id');

        $branches[] = Contract::query()
            ->where('contracts.organization_id', $this->organization->id)
            ->whereIn('contracts.catalog_plan_id', function ($query) use ($like): void {
                $query->select('id')
                    ->from('catalog_plans')
                    ->where('catalog_plans.organization_id', $this->organization->id)
                    ->where(function ($q) use ($like): void {
                        $q->where('catalog_plans.name', 'ILIKE', $like)
                            ->orWhere('catalog_plans.code', 'ILIKE', $like);
                    });
            })
            ->select('contracts.id');

        if (preg_match(self::UUID_REGEX, $term) === 1) {
            $branches[] = $base()->whereKey($term)->select('contracts.id');
        }

        if (($this->filters['external_customer_id'] ?? null) === null) {
            $branches[] = Contract::query()
                ->where('contracts.organization_id', $this->organization->id)
                ->whereIn('contracts.customer_id', function ($query) use ($like): void {
                    $query->select('id')
                        ->from('customers')
                        ->where('customers.organization_id', $this->organization->id)
                        ->where(function ($q) use ($like): void {
                            $q->where('customers.name', 'ILIKE', $like)
                                ->orWhere('customers.firstname', 'ILIKE', $like)
                                ->orWhere('customers.lastname', 'ILIKE', $like)
                                ->orWhere('customers.external_id', 'ILIKE', $like)
                                ->orWhere('customers.email', 'ILIKE', $like);
                        });
                })
                ->select('contracts.id');
        }

        $union = null;
        foreach ($branches as $branch) {
            $union = $union === null ? $branch : $union->unionAll($branch);
        }

        return $union->pluck('id');
    }

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
