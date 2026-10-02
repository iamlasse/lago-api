<?php

declare(strict_types=1);

namespace App\Queries;

use stdClass;
use App\Models\Customer;
use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\CustomerMetadata;
use App\Services\Validators\Countries;
use App\Services\Validators\Currencies;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

use function is_array;
use function is_string;

/**
 * Port of Rails' CustomersQuery (app/queries/customers_query.rb), including
 * the Queries::CustomersQueryFiltersContract validation (dry-validation
 * messages reproduced verbatim).
 *
 * Differences forced by PHP, noted for parity review:
 * - the SQL shape of the search branch is an OR over the searchable fields
 *   instead of a UNION of per-field subqueries (same result set);
 * - array-element validation errors are carried as (object) maps keyed by
 *   the element index — PHP cannot hold the literal string key "0" in an
 *   array, and json_encode of an int-keyed array would emit a list instead
 *   of Rails' {"0": [...]}.
 */
class CustomersQuery extends BaseService
{
    private const SEARCHABLE_FIELDS = ['name', 'firstname', 'lastname', 'legal_name', 'external_id', 'email'];

    private const UUID_REGEX = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

    /** Kaminari's default per page, used when the limit param is nil. */
    private const DEFAULT_PER_PAGE = 100;

    public function __construct(
        private readonly Organization $organization,
        private readonly array $pagination = ['page' => null, 'limit' => null],
        private readonly ?string $searchTerm = null,
        private readonly array $filters = [],
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('customers');

        $errors = $this->validateFilters();

        if ($errors !== []) {
            return $result->validationFailure($errors);
        }

        $customers = $this->baseScope();

        $customers = $this->withExternalId($customers) ?? $customers;
        $customers = $this->withCustomerType($customers) ?? $customers;
        $customers = $this->withAccountType($customers) ?? $customers;
        $customers = $this->withBillingEntityIds($customers) ?? $customers;
        $customers = $this->withBillingAddressFilter($customers) ?? $customers;
        $customers = $this->withCurrencies($customers) ?? $customers;
        $customers = $this->withHasTaxIdentificationNumber($customers) ?? $customers;
        $customers = $this->withMetadata($customers) ?? $customers;

        $result->customers = $this->paginate($customers);

        return $result;
    }

    /**
     * A PHP array cannot hold the literal string key "0" (it is normalized
     * to int, which json_encode renders as a list) — Rails emits
     * {"0": [...]} — so element-keyed errors are wrapped in an object.
     *
     * @param  array<int, mixed>  $errors
     */
    private static function indexedErrors(array $errors): object
    {
        $object = new stdClass();

        foreach ($errors as $index => $message) {
            $object->{(string) $index} = $message;
        }

        return $object;
    }

    /**
     * Rails: `base_scope` — organization scope, narrowed by the search term
     * over the searchable fields (unless an explicit external_id filter is
     * given).
     */
    private function baseScope(): \Illuminate\Database\Eloquent\Builder
    {
        $scope = Customer::query()->where('organization_id', $this->organization->id);

        $searchTerm = (string) ($this->searchTerm ?? '');

        if ($searchTerm === '' || (($this->filters['external_id'] ?? null) !== null && ($this->filters['external_id'] ?? '') !== '')) {
            return $scope;
        }

        return $scope->whereIn('id', $this->matchingIdsBySearch($searchTerm));
    }

    /** @return \Illuminate\Support\Collection<int, string> */
    private function matchingIdsBySearch(string $searchTerm): \Illuminate\Support\Collection
    {
        $escapedTerm = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $searchTerm).'%';

        return Customer::query()
            ->where('organization_id', $this->organization->id)
            ->where(function (\Illuminate\Database\Eloquent\Builder $query) use ($escapedTerm): void {
                foreach (self::SEARCHABLE_FIELDS as $field) {
                    $query->orWhere($field, 'ILIKE', $escapedTerm);
                }
            })
            ->pluck('id');
    }

    private function withExternalId(\Illuminate\Database\Eloquent\Builder $scope): ?\Illuminate\Database\Eloquent\Builder
    {
        $externalId = $this->filters['external_id'] ?? null;

        if ($externalId === null || $externalId === '') {
            return null;
        }

        return $scope->where('external_id', $externalId);
    }

    private function withCurrencies(\Illuminate\Database\Eloquent\Builder $scope): ?\Illuminate\Database\Eloquent\Builder
    {
        $currencies = $this->filters['currencies'] ?? null;

        if (! $this->present($currencies)) {
            return null;
        }

        return $scope->whereIn('currency', $currencies);
    }

    private function withBillingAddressFilter(\Illuminate\Database\Eloquent\Builder $scope): ?\Illuminate\Database\Eloquent\Builder
    {
        if (! $this->present($this->filters['countries'] ?? null)
            && ! $this->present($this->filters['states'] ?? null)
            && ! $this->present($this->filters['zipcodes'] ?? null)
        ) {
            return null;
        }

        if ($this->present($this->filters['countries'] ?? null)) {
            $scope = $scope->whereIn('country', $this->filters['countries']);
        }

        if ($this->present($this->filters['states'] ?? null)) {
            $scope = $scope->whereIn('state', $this->filters['states']);
        }

        if ($this->present($this->filters['zipcodes'] ?? null)) {
            $scope = $scope->whereIn('zipcode', $this->filters['zipcodes']);
        }

        return $scope;
    }

    private function withMetadata(\Illuminate\Database\Eloquent\Builder $scope): ?\Illuminate\Database\Eloquent\Builder
    {
        $metadata = $this->filters['metadata'] ?? null;

        if (! is_array($metadata) || $metadata === []) {
            return null;
        }

        $presenceFilters = [];
        $absenceFilters = [];

        foreach ($metadata as $key => $value) {
            if ($value !== null && $value !== '') {
                $presenceFilters[$key] = $value;
            } else {
                $absenceFilters[] = $key;
            }
        }

        if ($presenceFilters !== []) {
            $subquery = CustomerMetadata::query()
                ->where('organization_id', $this->organization->id)
                ->where(function (\Illuminate\Database\Eloquent\Builder $query) use ($presenceFilters): void {
                    foreach ($presenceFilters as $key => $value) {
                        $query->orWhere(fn (\Illuminate\Database\Eloquent\Builder $pair): \Illuminate\Database\Eloquent\Builder => $pair
                            ->where('key', $key)
                            ->where('value', $value));
                    }
                })
                ->groupBy('customer_id')
                ->havingRaw('COUNT(DISTINCT key) = ?', [count($presenceFilters)])
                ->select('customer_id');

            $scope = $scope->whereIn('id', $subquery);
        }

        if ($absenceFilters !== []) {
            $subquery = CustomerMetadata::query()
                ->where('organization_id', $this->organization->id)
                ->whereIn('key', $absenceFilters)
                ->select('customer_id');

            $scope = $scope->whereNotIn('id', $subquery);
        }

        return $scope;
    }

    private function withHasTaxIdentificationNumber(\Illuminate\Database\Eloquent\Builder $scope): ?\Illuminate\Database\Eloquent\Builder
    {
        if (! array_key_exists('has_tax_identification_number', $this->filters)) {
            return null;
        }

        if ($this->booleanCast($this->filters['has_tax_identification_number'])) {
            return $scope->whereNotNull('tax_identification_number');
        }

        return $scope->whereNull('tax_identification_number');
    }

    private function withCustomerType(\Illuminate\Database\Eloquent\Builder $scope): ?\Illuminate\Database\Eloquent\Builder
    {
        $customerType = $this->filters['customer_type'] ?? null;

        if ($this->present($customerType)) {
            return $scope->where('customer_type', $customerType);
        }

        if (array_key_exists('has_customer_type', $this->filters)) {
            if ($this->booleanCast($this->filters['has_customer_type'])) {
                return $scope->whereNotNull('customer_type');
            }

            return $scope->whereNull('customer_type');
        }

        return null;
    }

    private function withAccountType(\Illuminate\Database\Eloquent\Builder $scope): ?\Illuminate\Database\Eloquent\Builder
    {
        $accountType = $this->filters['account_type'] ?? null;

        if (! $this->present($accountType)) {
            return null;
        }

        return $scope->whereIn('account_type', $accountType);
    }

    private function withBillingEntityIds(\Illuminate\Database\Eloquent\Builder $scope): ?\Illuminate\Database\Eloquent\Builder
    {
        $billingEntityIds = $this->filters['billing_entity_ids'] ?? null;

        if (! $this->present($billingEntityIds)) {
            return null;
        }

        return $scope->whereIn('billing_entity_id', $billingEntityIds);
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

        // Rails applies apply_consistent_ordering after paginate.
        return $scope
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /** Ruby `present?` — non-null and non-empty. */
    private function present(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === []) {
            return false;
        }

        return true;
    }

    /** Port of ActiveModel::Type::Boolean#cast (the values that matter here). */
    private function booleanCast(mixed $value): bool
    {
        return in_array($value, ['true', 'TRUE', 't', 'T', '1', 1, true], true);
    }

    /**
     * Port of Queries::CustomersQueryFiltersContract — the dry-validation
     * error hash (field => [messages]), with element errors keyed by index.
     *
     * @return array<string, mixed>
     */
    private function validateFilters(): array
    {
        $errors = [];
        $filters = $this->filters;

        $accountTypeErrors = $this->arrayIncludedInErrors(
            $filters['account_type'] ?? null,
            Customer::ACCOUNT_TYPES,
        );
        if ($accountTypeErrors !== []) {
            $errors['account_type'] = self::indexedErrors($accountTypeErrors);
        }

        $billingEntityIdsErrors = [];
        $billingEntityIds = $filters['billing_entity_ids'] ?? null;
        if (is_array($billingEntityIds)) {
            foreach ($billingEntityIds as $index => $code) {
                if (! is_string($code) || preg_match(self::UUID_REGEX, $code) !== 1) {
                    $billingEntityIdsErrors[$index] = ['is invalid'];
                }
            }
        }
        if ($billingEntityIdsErrors !== []) {
            $errors['billing_entity_ids'] = self::indexedErrors($billingEntityIdsErrors);
        }

        $countryErrors = $this->arrayIncludedInErrors(
            $filters['countries'] ?? null,
            Countries::CODES,
        );
        if ($countryErrors !== []) {
            $errors['countries'] = self::indexedErrors($countryErrors);
        }

        $currencyErrors = $this->arrayIncludedInErrors(
            $filters['currencies'] ?? null,
            Currencies::list(),
        );
        if ($currencyErrors !== []) {
            $errors['currencies'] = self::indexedErrors($currencyErrors);
        }

        foreach (['has_tax_identification_number', 'has_customer_type'] as $key) {
            if (! array_key_exists($key, $filters)) {
                continue;
            }

            $value = is_scalar($filters[$key]) ? (string) $filters[$key] : null;

            if ($value === null || ! in_array($value, ['true', 'false'], true)) {
                $errors[$key] = ['must be one of: true, false'];
            }
        }

        $metadata = $filters['metadata'] ?? null;
        if (array_key_exists('metadata', $filters) && ! is_array($metadata)) {
            $errors['metadata'] = ['must be a hash'];
        } elseif (is_array($metadata)) {
            $metadataErrors = [];

            foreach ($metadata as $key => $value) {
                if (! is_string($value)) {
                    $metadataErrors[$key] = ['must be a string'];
                }
            }

            if ($metadataErrors !== []) {
                $errors['metadata'] = $metadataErrors;
            }
        }

        if (array_key_exists('customer_type', $filters)
            && $filters['customer_type'] !== null
            && $filters['customer_type'] !== ''
            && ! in_array($filters['customer_type'], Customer::CUSTOMER_TYPES, true)
        ) {
            $errors['customer_type'] = ['must be one of: '.implode(', ', Customer::CUSTOMER_TYPES)];
        }

        // Cross-field rule: customer_type must be nil when
        // has_customer_type is false.
        if (($filters['has_customer_type'] ?? null) === 'false'
            && ($filters['customer_type'] ?? null) !== null
            && ($filters['customer_type'] ?? null) !== ''
        ) {
            $errors['customer_type'][] = 'must be nil when has_customer_type is false';
        }

        return $errors;
    }

    /**
     * dry's `array(:string, included_in?: ...)` element errors, keyed by the
     * element index.
     *
     * @param  list<string>|null  $values
     * @param  list<string>  $allowed
     * @return array<int, list<string>>
     */
    private function arrayIncludedInErrors($values, array $allowed): array
    {
        if (! is_array($values)) {
            return [];
        }

        $errors = [];
        $message = 'must be one of: '.implode(', ', $allowed);

        foreach ($values as $index => $value) {
            if (! is_string($value)) {
                $errors[$index] = ['must be a string'];
            } elseif (! in_array($value, $allowed, true)) {
                $errors[$index] = [$message];
            }
        }

        return $errors;
    }
}
