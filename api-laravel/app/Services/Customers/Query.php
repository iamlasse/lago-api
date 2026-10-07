<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\Organization;
use App\Services\BaseResult;
use App\GraphQL\Support\Page;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Port of Rails' CustomersQuery (app/queries/customers_query.rb) — the query
 * object behind the `customers` GraphQL resolver: filters, search term and
 * kaminari pagination (page/limit, see App\GraphQL\Support\Page).
 *
 * Faithful to the Rails filter semantics:
 * - search term matches ANY of the searchable fields (Rails unions one
 *   ILIKE branch per field; the union here selects the same rows) and is
 *   skipped entirely when an `external_id` filter is present;
 * - `with_deleted` includes soft-deleted (discarded) customers;
 * - `metadata` maps key→value pairs: present values require the customer to
 *   carry ALL of them, blank values require the customer to carry NONE of
 *   those keys;
 * - consistent ordering: created_at DESC, then id ASC
 *   (Rails composes the order after pagination — with offset pagination the
 *   final SQL is identical, so here the order is applied before the page cut).
 *
 * TODO(port): Queries::CustomersQueryFiltersContract — Rails validates the
 * filters through a dry-validation contract before querying; the GraphQL
 * schema already constrains the shapes accepted here.
 */
class Query extends BaseService
{
    /** Rails: SEARCHABLE_FIELDS. */
    public const SEARCHABLE_FIELDS = ['name', 'firstname', 'lastname', 'legal_name', 'external_id', 'email'];

    /**
     * @param  array<string, mixed>  $filters  Rails-shaped (snake_case) filters
     * @param  array{page: ?int, limit: ?int}  $pagination
     */
    public function __construct(
        private readonly Organization $organization,
        private readonly array $filters = [],
        private readonly array $pagination = ['page' => null, 'limit' => null],
        private readonly ?string $searchTerm = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('customers');

        $customers = $this->baseScope();

        $customers = $this->withExternalId($customers);
        $customers = $this->withCustomerType($customers);
        $customers = $this->withAccountType($customers);
        $customers = $this->withBillingEntityIds($customers);
        $customers = $this->withActiveSubscriptionsRange($customers);
        $customers = $this->withBillingAddressFilter($customers);
        $customers = $this->withCurrencies($customers);
        $customers = $this->withHasTaxIdentificationNumber($customers);
        $customers = $this->withMetadata($customers);

        if ($this->filters['with_deleted'] ?? false) {
            $customers = $customers->withTrashed();
        }

        // Rails: paginate then apply_consistent_ordering — identical final
        // SQL for offset pagination.
        $customers = $this->applyConsistentOrdering($customers);
        $result->customers = $this->paginate($customers);

        return $result;
    }

    /**
     * Rails: base_scope — the organization's customers, narrowed by search
     * term unless an external_id filter is present.
     */
    private function baseScope(): Builder
    {
        $scope = Customer::query()->where('organization_id', $this->organization->id);

        $searchTerm = mb_trim((string) $this->searchTerm);
        if ($searchTerm === '' || ($this->filters['external_id'] ?? null) !== null) {
            return $scope;
        }

        // Rails: UNION of one ILIKE branch per searchable field over the
        // organization's customers (with_discarded when with_deleted asks
        // for it), matched back onto the customers table. The Eloquent
        // SoftDeletes scope keeps handling the outer scope's deleted_at.
        $escapedTerm = '%'.addcslashes($searchTerm, '\%_').'%';
        $discardClause = ($this->filters['with_deleted'] ?? false) ? '' : ' AND deleted_at IS NULL';

        $branches = collect(self::SEARCHABLE_FIELDS)
            ->map(fn (string $field): string => 'select id from customers where organization_id = ?'.
                $discardClause.
                ' and customers.'.$field.' ILIKE ?')
            ->implode(' UNION ');

        $bindings = collect(self::SEARCHABLE_FIELDS)
            ->flatMap(fn (): array => [$this->organization->id, $escapedTerm])
            ->all();

        // Binding order follows the compiled SQL: the organization_id filter
        // was added first, this raw IN clause second.
        return $scope->whereRaw("customers.id IN ({$branches})", $bindings);
    }

    private function withExternalId(Builder $scope): Builder
    {
        if (($this->filters['external_id'] ?? null) === null) {
            return $scope;
        }

        return $scope->where('external_id', $this->filters['external_id']);
    }

    private function withCurrencies(Builder $scope): Builder
    {
        $currencies = $this->filters['currencies'] ?? null;
        if ($currencies === null || $currencies === []) {
            return $scope;
        }

        return $scope->where('currency', $currencies);
    }

    private function withBillingAddressFilter(Builder $scope): Builder
    {
        $countries = $this->filters['countries'] ?? null;
        $states = $this->filters['states'] ?? null;
        $zipcodes = $this->filters['zipcodes'] ?? null;

        if ($countries !== null && $countries !== []) {
            $scope->where('country', $countries);
        }
        if ($states !== null && $states !== []) {
            $scope->where('state', $states);
        }
        if ($zipcodes !== null && $zipcodes !== []) {
            $scope->where('zipcode', $zipcodes);
        }

        return $scope;
    }

    private function withHasTaxIdentificationNumber(Builder $scope): Builder
    {
        if (! array_key_exists('has_tax_identification_number', $this->filters)) {
            return $scope;
        }

        if (filter_var($this->filters['has_tax_identification_number'], FILTER_VALIDATE_BOOLEAN)) {
            return $scope->whereNotNull('tax_identification_number');
        }

        return $scope->whereNull('tax_identification_number');
    }

    private function withCustomerType(Builder $scope): Builder
    {
        $customerType = $this->filters['customer_type'] ?? null;
        if ($customerType !== null) {
            return $scope->where('customer_type', $customerType);
        }

        if (! array_key_exists('has_customer_type', $this->filters)) {
            return $scope;
        }

        if (filter_var($this->filters['has_customer_type'], FILTER_VALIDATE_BOOLEAN)) {
            return $scope->whereNotNull('customer_type');
        }

        return $scope->whereNull('customer_type');
    }

    private function withAccountType(Builder $scope): Builder
    {
        $accountTypes = $this->filters['account_type'] ?? null;
        if ($accountTypes === null || $accountTypes === []) {
            return $scope;
        }

        return $scope->where('account_type', $accountTypes);
    }

    private function withBillingEntityIds(Builder $scope): Builder
    {
        $billingEntityIds = $this->filters['billing_entity_ids'] ?? null;
        if ($billingEntityIds === null || $billingEntityIds === []) {
            return $scope;
        }

        return $scope->where('billing_entity_id', $billingEntityIds);
    }

    private function withActiveSubscriptionsRange(Builder $scope): Builder
    {
        $from = $this->filters['active_subscriptions_count_from'] ?? null;
        $to = $this->filters['active_subscriptions_count_to'] ?? null;

        if ($from === null && $to === null) {
            return $scope;
        }

        // Rails: COUNT(CASE WHEN subscriptions.status = 1 THEN 1 END) —
        // status 1 is the `active` Subscription.
        $activeCount = 'COUNT(CASE WHEN subscriptions.status = 1 THEN 1 END)';
        $countScope = $scope->clone()
            ->select('customers.id')
            ->leftJoin('subscriptions', 'subscriptions.customer_id', '=', 'customers.id')
            ->groupBy('customers.id');

        $countScope = match (true) {
            $from !== null && $from === $to => $countScope->havingRaw("{$activeCount} = ?", [$from]),
            $from !== null && $to === null => $countScope->havingRaw("{$activeCount} > ?", [$from]),
            $from === null && $to !== null => $countScope->havingRaw("{$activeCount} < ?", [$to]),
            default => $countScope->havingRaw("{$activeCount} BETWEEN ? AND ?", [$from, $to]),
        };

        $ids = $countScope->pluck('customers.id');

        return $scope->whereIn('customers.id', $ids);
    }

    /**
     * Rails: with_metadata — `metadata` arrives as a key→value hash (the
     * GraphQL resolver folds the [CustomerMetadataFilter!] list).
     */
    private function withMetadata(Builder $scope): Builder
    {
        $metadata = $this->filters['metadata'] ?? null;
        if ($metadata === null || $metadata === []) {
            return $scope;
        }

        $presence = [];
        $absence = [];
        foreach ($metadata as $key => $value) {
            if ($value === null || $value === '') {
                $absence[] = $key;
            } else {
                $presence[$key] = $value;
            }
        }

        if ($presence !== []) {
            $subquery = DB::table('customer_metadata')
                ->where('organization_id', $this->organization->id)
                ->where(function ($query) use ($presence): void {
                    foreach ($presence as $key => $value) {
                        $query->orWhere(function ($q) use ($key, $value): void {
                            $q->where('key', $key)->where('value', $value);
                        });
                    }
                })
                ->groupBy('customer_id')
                ->havingRaw('COUNT(DISTINCT key) = ?', [count($presence)])
                ->select('customer_id');

            $scope->whereIn('customers.id', $subquery);
        }

        if ($absence !== []) {
            $subquery = DB::table('customer_metadata')
                ->where('organization_id', $this->organization->id)
                ->whereIn('key', $absence)
                ->select('customer_id');

            $scope->whereNotIn('customers.id', $subquery);
        }

        return $scope;
    }

    /** Rails: apply_consistent_ordering — created_at DESC, id ASC. */
    private function applyConsistentOrdering(Builder $scope): Builder
    {
        return $scope->latest()->orderBy('id', 'asc');
    }

    /** Rails: paginate — offset pagination through kaminari page/per. */
    private function paginate(Builder $scope): LengthAwarePaginator
    {
        [$page, $limit] = Page::normalizePageAndLimit(
            $this->pagination['page'] ?? null,
            $this->pagination['limit'] ?? null,
        );

        return $scope->paginate(perPage: $limit, page: $page);
    }
}
