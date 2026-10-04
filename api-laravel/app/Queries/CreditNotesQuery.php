<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Invoice;
use App\Models\Customer;
use App\Models\CreditNote;
use App\Models\Organization;
use App\Services\BaseResult;
use App\GraphQL\Support\Page;
use App\Services\BaseService;
use App\Enums\CreditNoteReason;
use App\Enums\CreditNoteCreditStatus;
use App\Enums\CreditNoteRefundStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Port of Rails' CreditNotesQuery (app/queries/credit_notes_query.rb) —
 * the query object behind the credit notes index (REST + GraphQL).
 *
 * Rails ransack semantics preserved: the search term narrows
 * id_cont OR number_cont, plus the customer name/external_id/email
 * predicates when the scope is not already narrowed to one customer; the
 * base scope is the organization's FINALIZED credit notes only.
 */
class CreditNotesQuery extends BaseService
{
    private const UUID_REGEX = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

    /** Rails: CreditNote::TYPES. */
    private const TYPES = ['credit', 'refund', 'offset'];

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
        $result = static::makeResult('credit_notes');

        $creditNotes = $this->baseScope();

        $creditNotes = $this->withBillingEntityIds($creditNotes) ?? $creditNotes;
        $creditNotes = $this->withCurrency($creditNotes) ?? $creditNotes;
        $creditNotes = $this->withCustomerExternalId($creditNotes) ?? $creditNotes;
        $creditNotes = $this->withCustomerId($creditNotes) ?? $creditNotes;
        $creditNotes = $this->withReason($creditNotes) ?? $creditNotes;
        $creditNotes = $this->withCreditStatus($creditNotes) ?? $creditNotes;
        $creditNotes = $this->withRefundStatus($creditNotes) ?? $creditNotes;
        $creditNotes = $this->withTypes($creditNotes) ?? $creditNotes;
        $creditNotes = $this->withInvoiceNumber($creditNotes) ?? $creditNotes;
        $creditNotes = $this->withPurchaseOrderNumber($creditNotes) ?? $creditNotes;
        $creditNotes = $this->withIssuingDateRange($creditNotes) ?? $creditNotes;
        $creditNotes = $this->withAmountRange($creditNotes) ?? $creditNotes;
        $creditNotes = $this->withSelfBilledInvoice($creditNotes) ?? $creditNotes;

        // Rails: paginate then apply_consistent_ordering (created_at desc,
        // id asc tiebreak) — applied before the SQL executes here so the
        // ordering lands inside the LIMIT/OFFSET query.
        $result->credit_notes = $this->paginate(
            $creditNotes
                ->latest('credit_notes.created_at')
                ->orderBy('credit_notes.id'),
        );

        return $result;
    }

    /**
     * Rails: `base_scope` — `CreditNote.where(organization:).finalized`,
     * then the ransack search. (`not_deleted` — Rails' discard scope — has
     * no port: the credit_notes table carries no deleted_at column.)
     */
    private function baseScope(): Builder
    {
        $scope = CreditNote::query()
            ->where('credit_notes.organization_id', $this->organization->id)
            ->finalized();

        $searchTerm = (string) ($this->searchTerm ?? '');

        if (mb_trim($searchTerm) === '') {
            return $scope;
        }

        $like = '%'.$this->escapeLike(mb_strtolower(mb_trim($searchTerm))).'%';

        return $scope->where(function (Builder $query) use ($like, $searchTerm): void {
            // Rails: m: "or", id_cont OR number_cont (the RansackUuidSearch
            // ransacker casts the uuid to varchar for the match).
            $query->whereRaw('LOWER(credit_notes.id::varchar) LIKE ?', [$like])
                ->orWhereRaw('LOWER(credit_notes.number) LIKE ?', [$like]);

            if (($this->filters['customer_id'] ?? null) === null) {
                $customerIds = Customer::query()
                    ->where(function (Builder $customerQuery) use ($like, $searchTerm): void {
                        $customerQuery
                            ->whereRaw('LOWER(customers.name) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(customers.firstname) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(customers.lastname) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(customers.external_id) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(customers.email) LIKE ?', [$like]);

                        if (preg_match(self::UUID_REGEX, $searchTerm) === 1) {
                            $customerQuery->orWhere('customers.id', $searchTerm);
                        }
                    })
                    ->select('id');

                $query->orWhereIn('credit_notes.customer_id', $customerIds);
            }
        });
    }

    /** Rails: `joins(:invoice).where(invoices: {billing_entity_id:})`. */
    private function withBillingEntityIds(Builder $scope): ?Builder
    {
        $billingEntityIds = $this->filters['billing_entity_ids'] ?? null;

        if (! $this->present($billingEntityIds)) {
            return null;
        }

        return $scope->whereIn(
            'credit_notes.invoice_id',
            Invoice::query()->whereIn('billing_entity_id', $billingEntityIds)->select('id'),
        );
    }

    private function withCurrency(Builder $scope): ?Builder
    {
        $currency = $this->filters['currency'] ?? null;

        if ($currency === null) {
            return null;
        }

        return $scope->where('credit_notes.total_amount_currency', $currency);
    }

    /** Rails: `joins(:customer).where(customers: {external_id:})`. */
    private function withCustomerExternalId(Builder $scope): ?Builder
    {
        $externalCustomerId = $this->filters['customer_external_id'] ?? null;

        if ($externalCustomerId === null) {
            return null;
        }

        return $scope->whereIn(
            'credit_notes.customer_id',
            Customer::query()->where('external_id', $externalCustomerId)->select('id'),
        );
    }

    private function withCustomerId(Builder $scope): ?Builder
    {
        $customerId = $this->filters['customer_id'] ?? null;

        if (! $this->present($customerId)) {
            return null;
        }

        return $scope->where('credit_notes.customer_id', $customerId);
    }

    /** Rails: `where(reason: valid_reasons)` — unknown names are dropped. */
    private function withReason(Builder $scope): ?Builder
    {
        $values = $this->validNames($this->filters['reason'] ?? null, CreditNoteReason::fromOption(...));

        if ($values === null) {
            return null;
        }

        return $scope->whereIn('credit_notes.reason', $values);
    }

    private function withCreditStatus(Builder $scope): ?Builder
    {
        $values = $this->validNames($this->filters['credit_status'] ?? null, CreditNoteCreditStatus::fromOption(...));

        if ($values === null) {
            return null;
        }

        return $scope->whereIn('credit_notes.credit_status', $values);
    }

    private function withRefundStatus(Builder $scope): ?Builder
    {
        $values = $this->validNames($this->filters['refund_status'] ?? null, CreditNoteRefundStatus::fromOption(...));

        if ($values === null) {
            return null;
        }

        return $scope->whereIn('credit_notes.refund_status', $values);
    }

    /**
     * Rails: `with_types` — credit/refund/offset become OR'd positive-amount
     * predicates; no recognized type means `scope.none`.
     */
    private function withTypes(Builder $scope): ?Builder
    {
        $types = $this->filters['types'] ?? null;

        if (! $this->present($types)) {
            return null;
        }

        $validTypes = array_values(array_intersect(
            array_map(strval(...), (array) $types),
            self::TYPES,
        ));

        if ($validTypes === []) {
            return $scope->none();
        }

        $predicates = [];

        if (in_array('credit', $validTypes, true)) {
            $predicates[] = 'credit_notes.credit_amount_cents > 0';
        }

        if (in_array('refund', $validTypes, true)) {
            $predicates[] = 'credit_notes.refund_amount_cents > 0';
        }

        if (in_array('offset', $validTypes, true)) {
            $predicates[] = 'credit_notes.offset_amount_cents > 0';
        }

        return $scope->whereRaw('('.implode(' OR ', $predicates).')');
    }

    /** Rails: `joins(:invoice).where(invoices: {number:})`. */
    private function withInvoiceNumber(Builder $scope): ?Builder
    {
        $invoiceNumber = $this->filters['invoice_number'] ?? null;

        if (! $this->present($invoiceNumber)) {
            return null;
        }

        return $scope->whereIn(
            'credit_notes.invoice_id',
            Invoice::query()->where('number', $invoiceNumber)->select('id'),
        );
    }

    /** Rails: case-insensitive purchase_order_number match, org-scoped. */
    private function withPurchaseOrderNumber(Builder $scope): ?Builder
    {
        $purchaseOrderNumber = $this->filters['purchase_order_number'] ?? null;

        if (! $this->present($purchaseOrderNumber)) {
            return null;
        }

        return $scope->whereIn(
            'credit_notes.invoice_id',
            Invoice::query()
                ->where('organization_id', $this->organization->id)
                ->whereRaw('lower(invoices.purchase_order_number) = lower(?)', [$purchaseOrderNumber])
                ->select('id'),
        );
    }

    /** Rails: `with_issuing_date_range` — half-open ranges when an end is absent. */
    private function withIssuingDateRange(Builder $scope): ?Builder
    {
        $from = $this->dateFilter('issuing_date_from');
        $to = $this->dateFilter('issuing_date_to');

        if ($from === null && $to === null) {
            return null;
        }

        if ($from !== null) {
            $scope = $scope->where('credit_notes.issuing_date', '>=', $from);
        }

        if ($to !== null) {
            $scope = $scope->where('credit_notes.issuing_date', '<=', $to);
        }

        return $scope;
    }

    /** Rails: `with_amount_range` over total_amount_cents. */
    private function withAmountRange(Builder $scope): ?Builder
    {
        $amountFrom = $this->filters['amount_from'] ?? null;
        $amountTo = $this->filters['amount_to'] ?? null;

        if ($amountFrom === null && $amountTo === null) {
            return null;
        }

        if ($amountFrom !== null) {
            $scope = $scope->whereRaw('credit_notes.total_amount_cents >= ?::numeric', [$amountFrom]);
        }

        if ($amountTo !== null) {
            $scope = $scope->whereRaw('credit_notes.total_amount_cents <= ?::numeric', [$amountTo]);
        }

        return $scope;
    }

    /** Rails: `joins(:invoice).where(invoices: {self_billed: Boolean(filters.self_billed)})`. */
    private function withSelfBilledInvoice(Builder $scope): ?Builder
    {
        $selfBilled = $this->filters['self_billed'] ?? null;

        if ($selfBilled === null) {
            return null;
        }

        return $scope->whereIn(
            'credit_notes.invoice_id',
            Invoice::query()->where('self_billed', $this->booleanCast($selfBilled))->select('id'),
        );
    }

    /**
     * Rails: Array(value).select { valid enum key } mapped to the stored
     * values — returns null when nothing valid remains (filter skipped).
     *
     * @return list<int>|null
     */
    private function validNames(mixed $value, callable $fromOption): ?array
    {
        if ($value === null) {
            return null;
        }

        $values = [];

        foreach ((array) $value as $name) {
            $option = $fromOption($name);

            if ($option !== null) {
                $values[] = $option;
            }
        }

        return $values === [] ? null : $values;
    }

    /** Rails: `parse_datetime_filter` normalizes to the Y-m-d comparison. */
    private function dateFilter(string $key): ?string
    {
        $value = $this->filters[$key] ?? null;

        if ($value instanceof \Carbon\CarbonInterface) {
            return $value->toDateString();
        }

        return is_string($value) && $value !== '' ? mb_substr($value, 0, 10) : null;
    }

    /** Rails: `paginate` — kaminari page/per over the scope. */
    private function paginate(Builder $scope): LengthAwarePaginator
    {
        [$page, $limit] = Page::normalizePageAndLimit(
            is_numeric($this->pagination['page'] ?? null) ? (int) $this->pagination['page'] : null,
            is_numeric($this->pagination['limit'] ?? null) ? (int) $this->pagination['limit'] : null,
        );

        return $scope->paginate(perPage: $limit, page: $page);
    }

    /** Ruby `present?` — non-null and non-empty. */
    private function present(mixed $value): bool
    {
        return ! ($value === null || $value === '' || $value === []);
    }

    /** Port of ActiveModel::Type::Boolean#cast (the values that matter here). */
    private function booleanCast(mixed $value): bool
    {
        return in_array($value, ['true', 'TRUE', 't', 'T', '1', 1, true], true);
    }

    /** Port of `ActiveRecord::Sanitization.sanitize_sql_like`. */
    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
    }
}
