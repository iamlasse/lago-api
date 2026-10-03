<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Models\Customer;
use App\Enums\InvoiceType;
use Carbon\CarbonInterface;
use App\Enums\InvoiceStatus;
use App\Models\Organization;
use App\Services\BaseResult;
use App\GraphQL\Support\Page;
use App\Services\BaseService;
use App\Enums\InvoicePaymentStatus;
use App\Models\InvoiceSubscription;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

use function is_array;

/**
 * Port of Rails' InvoicesQuery (app/queries/invoices_query.rb) — the query
 * object behind the `invoices` GraphQL resolver.
 *
 * Faithful to the Rails filter semantics, with the gaps the port carries:
 * - the `status` filter intersects with Invoice::VISIBLE_STATUS and is
 *   applied unconditionally (the default keeps only the visible statuses);
 * - the search term narrows `invoices.search_terms` (or `invoices.number`
 *   when the query is already scoped to one customer), with the UUID
 *   escape hatch matching `invoices.id`;
 * - the `metadata` filter never arrives from the GraphQL wire (the frozen
 *   schema carries no `metadata` argument) and is therefore not ported;
 * - the `settlements` filter validates against the wire's single
 *   `credit_note` value but is NOT applied — the `invoice_settlements`
 *   table ships with the credit-notes slice (TODO(port)).
 */
class Query extends BaseService
{
    private const UUID_REGEX = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

    /** Rails: Invoice::VISIBLE_STATUS names. */
    private const VISIBLE_STATUS_NAMES = ['draft', 'finalized', 'voided', 'failed', 'pending'];

    /** Rails: Invoice::PAYMENT_STATUS names. */
    private const PAYMENT_STATUS_NAMES = ['pending', 'succeeded', 'failed'];

    /** Rails: InvoiceSettlement.settlement_types keys (the ported subset). */
    private const SETTLEMENT_TYPES = ['credit_note'];

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
        $result = static::makeResult('invoices');

        $validationErrors = $this->validateFilters();

        if ($validationErrors !== null) {
            return $result->validationFailure($validationErrors);
        }

        $result->invoices = $this->paginate($this->invoices());

        return $result;
    }

    /**
     * Port of Queries::InvoicesQueryFiltersContract — only the checks the
     * GraphQL wire can actually trip (the schema already constrains the
     * enums/booleans; `billing_entity_ids` accepts free IDs).
     *
     * @return array<string, list<string>>|null
     */
    private function validateFilters(): ?array
    {
        $errors = [];

        $billingEntityIds = $this->filters['billing_entity_ids'] ?? null;

        if ($billingEntityIds !== null) {
            foreach ((array) $billingEntityIds as $id) {
                if (! is_string($id) || preg_match(self::UUID_REGEX, $id) !== 1) {
                    $errors['billing_entity_ids'] = ['is invalid'];

                    break;
                }
            }
        }

        $status = $this->filters['status'] ?? null;

        if ($status !== null) {
            foreach ((array) $status as $name) {
                if (! in_array((string) $name, self::VISIBLE_STATUS_NAMES, true)) {
                    $errors['status'] = ['must be one of: '.implode(', ', self::VISIBLE_STATUS_NAMES)];

                    break;
                }
            }
        }

        $paymentStatus = $this->filters['payment_status'] ?? null;

        if ($paymentStatus !== null) {
            foreach ((array) $paymentStatus as $name) {
                if (! in_array((string) $name, self::PAYMENT_STATUS_NAMES, true)) {
                    $errors['payment_status'] = ['must be one of: '.implode(', ', self::PAYMENT_STATUS_NAMES)];

                    break;
                }
            }
        }

        $settlements = $this->filters['settlements'] ?? null;

        if ($settlements !== null) {
            foreach ((array) $settlements as $name) {
                if (! in_array((string) $name, self::SETTLEMENT_TYPES, true)) {
                    $errors['settlements'] = ['must be one of: '.implode(', ', self::SETTLEMENT_TYPES)];

                    break;
                }
            }
        }

        foreach (['self_billed', 'partially_paid', 'payment_overdue'] as $booleanFilter) {
            $value = $this->filters[$booleanFilter] ?? null;

            if ($value !== null && ! is_bool($value)) {
                $errors[$booleanFilter] = ['must be boolean'];
            }
        }

        return $errors === [] ? null : $errors;
    }

    /** Rails: `invoices` — the filter chain over the base scope. */
    private function invoices(): Builder
    {
        $invoices = $this->baseScope();

        $invoices = $this->withBillingEntityIds($invoices) ?? $invoices;
        $invoices = $this->withCurrency($invoices) ?? $invoices;
        $invoices = $this->withCustomerExternalId($invoices) ?? $invoices;
        $invoices = $this->withCustomerId($invoices) ?? $invoices;
        $invoices = $this->withInvoiceType($invoices) ?? $invoices;
        $invoices = $this->withIssuingDateRange($invoices) ?? $invoices;
        $invoices = $this->withStatus($invoices);
        $invoices = $this->withPaymentStatus($invoices) ?? $invoices;
        $invoices = $this->withPaymentDisputeLost($invoices) ?? $invoices;
        $invoices = $this->withPaymentOverdue($invoices) ?? $invoices;
        $invoices = $this->withAmountRange($invoices) ?? $invoices;
        $invoices = $this->withPartiallyPaid($invoices) ?? $invoices;
        $invoices = $this->withPositiveDueAmount($invoices) ?? $invoices;
        $invoices = $this->withPurchaseOrderNumber($invoices) ?? $invoices;
        $invoices = $this->withSelfBilled($invoices) ?? $invoices;
        $invoices = $this->withSubscriptionId($invoices) ?? $invoices;

        // Rails: with_settlements when valid_settlements present — the
        // invoice_settlements table is not ported yet (TODO(port)), so the
        // filter is deliberately skipped.

        return $this->applyConsistentOrdering($invoices);
    }

    /**
     * Rails: `base_scope` — organization scope, narrowed by the search term
     * (Rails preloads file/xml attachments here; both stay unported).
     */
    private function baseScope(): Builder
    {
        $scope = Invoice::query()->where('invoices.organization_id', $this->organization->id);

        $searchTerm = (string) ($this->searchTerm ?? '');

        if ($searchTerm === '') {
            return $scope;
        }

        $escapedTerm = '%'.$this->escapeLike($searchTerm).'%';
        $column = $this->searchCustomers() ? 'invoices.search_terms' : 'invoices.number';

        return $scope->where(function (Builder $query) use ($column, $escapedTerm, $searchTerm): void {
            $query->where($column, 'ILIKE', $escapedTerm);

            if (preg_match(self::UUID_REGEX, $searchTerm) === 1) {
                $query->orWhere('invoices.id', $searchTerm);
            }
        });
    }

    /** Rails: `search_customers?` — only when no customer id/external id filter. */
    private function searchCustomers(): bool
    {
        return ($this->filters['customer_id'] ?? null) === null
            && ($this->filters['customer_external_id'] ?? null) === null;
    }

    private function withBillingEntityIds(Builder $scope): ?Builder
    {
        $billingEntityIds = $this->filters['billing_entity_ids'] ?? null;

        if (! $this->present($billingEntityIds)) {
            return null;
        }

        return $scope->whereIn('invoices.billing_entity_id', $billingEntityIds);
    }

    private function withCurrency(Builder $scope): ?Builder
    {
        $currency = $this->filters['currency'] ?? null;

        if ($currency === null) {
            return null;
        }

        return $scope->where('invoices.currency', $currency);
    }

    private function withCustomerExternalId(Builder $scope): ?Builder
    {
        $customerExternalId = $this->filters['customer_external_id'] ?? null;

        if ($customerExternalId === null) {
            return null;
        }

        return $scope->whereIn(
            'invoices.customer_id',
            Customer::query()->where('external_id', $customerExternalId)->select('id'),
        );
    }

    private function withCustomerId(Builder $scope): ?Builder
    {
        $customerId = $this->filters['customer_id'] ?? null;

        if (! $this->present($customerId)) {
            return null;
        }

        return $scope->where('invoices.customer_id', $customerId);
    }

    private function withInvoiceType(Builder $scope): ?Builder
    {
        $invoiceType = $this->filters['invoice_type'] ?? null;

        if ($invoiceType === null || $invoiceType === []) {
            return null;
        }

        $values = [];

        foreach ((array) $invoiceType as $name) {
            $value = InvoiceType::fromOption($name);

            if ($value !== null) {
                $values[] = $value;
            }
        }

        return $scope->whereIn('invoices.invoice_type', $values);
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
            $scope = $scope->where('invoices.issuing_date', '>=', $from);
        }

        if ($to !== null) {
            $scope = $scope->where('invoices.issuing_date', '<=', $to);
        }

        return $scope;
    }

    /**
     * Rails: `with_status` — always applied; the requested statuses
     * intersect with the visible ones (the default keeps only visible).
     */
    private function withStatus(Builder $scope): Builder
    {
        $requested = $this->filters['status'] ?? null;

        $names = is_array($requested) && $requested !== []
            ? array_map(strval(...), $requested)
            : self::VISIBLE_STATUS_NAMES;

        $values = [];

        foreach ($names as $name) {
            $value = InvoiceStatus::fromOption($name);

            if ($value !== null && in_array($name, self::VISIBLE_STATUS_NAMES, true)) {
                $values[] = $value;
            }
        }

        return $scope->whereIn('invoices.status', $values);
    }

    private function withPaymentStatus(Builder $scope): ?Builder
    {
        $paymentStatus = $this->filters['payment_status'] ?? null;

        if ($paymentStatus === null || $paymentStatus === []) {
            return null;
        }

        $values = [];

        foreach ((array) $paymentStatus as $name) {
            $value = InvoicePaymentStatus::fromOption($name);

            if ($value !== null) {
                $values[] = $value;
            }
        }

        return $scope->whereIn('invoices.payment_status', $values);
    }

    private function withPaymentDisputeLost(Builder $scope): ?Builder
    {
        $paymentDisputeLost = $this->filters['payment_dispute_lost'] ?? null;

        if ($paymentDisputeLost === null) {
            return null;
        }

        if ($this->booleanCast($paymentDisputeLost)) {
            return $scope->whereNotNull('invoices.payment_dispute_lost_at');
        }

        return $scope->whereNull('invoices.payment_dispute_lost_at');
    }

    private function withPaymentOverdue(Builder $scope): ?Builder
    {
        $paymentOverdue = $this->filters['payment_overdue'] ?? null;

        if ($paymentOverdue === null) {
            return null;
        }

        return $scope->where('invoices.payment_overdue', $this->booleanCast($paymentOverdue));
    }

    /** Rails: `with_amount_range` — `::numeric` casts keep bigint ranges exact. */
    private function withAmountRange(Builder $scope): ?Builder
    {
        $amountFrom = $this->filters['amount_from'] ?? null;
        $amountTo = $this->filters['amount_to'] ?? null;

        if ($amountFrom === null && $amountTo === null) {
            return null;
        }

        if ($amountFrom !== null) {
            $scope = $scope->whereRaw('invoices.total_amount_cents >= ?::numeric', [$amountFrom]);
        }

        if ($amountTo !== null) {
            $scope = $scope->whereRaw('invoices.total_amount_cents <= ?::numeric', [$amountTo]);
        }

        return $scope;
    }

    private function withPartiallyPaid(Builder $scope): ?Builder
    {
        $partiallyPaid = $this->filters['partially_paid'] ?? null;

        if ($partiallyPaid === null) {
            return null;
        }

        if ($this->booleanCast($partiallyPaid)) {
            return $scope->whereRaw('(invoices.total_amount_cents > invoices.total_paid_amount_cents AND invoices.total_paid_amount_cents > 0)');
        }

        return $scope->whereRaw('(invoices.total_amount_cents = invoices.total_paid_amount_cents OR invoices.total_paid_amount_cents = 0)');
    }

    private function withPositiveDueAmount(Builder $scope): ?Builder
    {
        $positiveDueAmount = $this->filters['positive_due_amount'] ?? null;

        if ($positiveDueAmount === null) {
            return null;
        }

        if ($this->booleanCast($positiveDueAmount)) {
            return $scope->whereRaw('invoices.total_amount_cents - invoices.total_paid_amount_cents > 0');
        }

        return $scope->whereRaw('invoices.total_amount_cents - invoices.total_paid_amount_cents <= 0');
    }

    private function withPurchaseOrderNumber(Builder $scope): ?Builder
    {
        $purchaseOrderNumber = $this->filters['purchase_order_number'] ?? null;

        if (! $this->present($purchaseOrderNumber)) {
            return null;
        }

        // NOTE: case-insensitive match, exactly like Rails (the
        // organization_id + lower() index covers the organization scope).
        return $scope->whereRaw('lower(invoices.purchase_order_number) = lower(?)', [$purchaseOrderNumber]);
    }

    private function withSelfBilled(Builder $scope): ?Builder
    {
        $selfBilled = $this->filters['self_billed'] ?? null;

        if ($selfBilled === null) {
            return null;
        }

        return $scope->where('invoices.self_billed', $this->booleanCast($selfBilled));
    }

    private function withSubscriptionId(Builder $scope): ?Builder
    {
        $subscriptionId = $this->filters['subscription_id'] ?? null;

        if (! $this->present($subscriptionId)) {
            return null;
        }

        // Rails joins :invoice_subscriptions; the EXISTS-shaped subquery
        // selects the same rows without duplicating invoices.
        return $scope->whereIn(
            'invoices.id',
            InvoiceSubscription::query()
                ->where('subscription_id', $subscriptionId)
                ->select('invoice_id'),
        );
    }

    /** Rails: apply_consistent_ordering(default_order: issuing_date DESC, created_at DESC). */
    private function applyConsistentOrdering(Builder $scope): Builder
    {
        return $scope
            ->orderByRaw('invoices.issuing_date desc')
            ->orderByRaw('invoices.created_at desc')
            ->orderBy('invoices.id');
    }

    /**
     * Rails: the resolver preloads :fees, :applied_taxes, :regenerated_invoice,
     * :billing_entity and {customer: :billing_entity} (error_details and
     * customer_payments stay unported).
     */
    private function paginate(Builder $scope): LengthAwarePaginator
    {
        [$page, $limit] = Page::normalizePageAndLimit(
            $this->pagination['page'] ?? null,
            $this->pagination['limit'] ?? null,
        );

        return $scope
            ->with(['fees', 'appliedTaxes', 'billingEntity', 'customer.billingEntity'])
            ->paginate(perPage: $limit, page: $page);
    }

    /**
     * Rails: `parse_datetime_filter` — the GraphQL scalar already hands over
     * a UTC-midnight Carbon; normalize to the `Y-m-d` string the date
     * column compares against.
     */
    private function dateFilter(string $key): ?string
    {
        $value = $this->filters[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->toDateString();
        }

        return is_string($value) ? mb_substr($value, 0, 10) : null;
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

    /** Port of `ActiveRecord::Sanitization.sanitize_sql_like`. */
    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
    }
}
