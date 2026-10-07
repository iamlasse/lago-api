<?php

declare(strict_types=1);

namespace App\Queries;

use Throwable;
use App\Models\Order;
use App\Models\QuoteOwner;
use App\Services\BaseResult;
use App\GraphQL\Support\Page;
use App\Services\BaseService;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Port of Rails' OrdersQuery (app/queries/orders_query.rb).
 *
 * Filters: status, order_type, execution_mode, customer_id, number,
 * order_form_number, quote_number, owner_id, executed_at_from/to. Scalar
 * values (REST) and lists (GraphQL) are both accepted for the enumerated /
 * identifier filters — Rails' `where(status: value)` reads the same either
 * way.
 *
 * TODO(port): Queries::OrdersQueryFiltersContract (the dry-validation
 * contract on the filter shapes) — the port is lenient about shapes until
 * the shared filter-contract port lands.
 */
class OrdersQuery extends BaseService
{
    public function __construct(
        private readonly object $organization,
        private readonly array $pagination = ['page' => null, 'limit' => null],
        private readonly array $filters = [],
        private readonly ?string $searchTerm = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('orders');

        // select(orders.*) — the order_type / owner filters join
        // quote_versions / quotes, whose status column would otherwise
        // shadow orders.status on hydration.
        $orders = Order::query()
            ->select('orders.*')
            ->where('orders.organization_id', $this->organization->id);

        $orders = $this->withSearchTerm($orders);

        $filters = $this->filters;

        // Rails: base_scope = organization.orders
        //   .preload(order_form: {quote_version: :quote}).
        $orders->with(['orderForm.quoteVersion']);

        if ($this->present('status')) {
            $orders->where('orders.status', $filters['status']);
        }

        if ($this->present('order_type')) {
            // Rails: joins(order_form: :quote).where(quotes: {order_type:}).
            $orders->join('order_forms', 'order_forms.id', '=', 'orders.order_form_id')
                ->join('quote_versions', 'quote_versions.id', '=', 'order_forms.quote_version_id')
                ->join('quotes', 'quotes.id', '=', 'quote_versions.quote_id')
                ->where('quotes.order_type', $filters['order_type']);
        }

        if ($this->present('execution_mode')) {
            $orders->where('orders.execution_mode', $filters['execution_mode']);
        }

        if ($this->present('customer_id')) {
            $orders->where('orders.customer_id', $filters['customer_id']);
        }

        if ($this->present('number')) {
            $orders->where('orders.number', $filters['number']);
        }

        if ($this->present('order_form_number')) {
            // Rails: joins(:order_form).where(order_forms: {number:}).
            $orders->join('order_forms as order_form_number_filter', function ($join): void {
                $join->on('order_form_number_filter.id', '=', 'orders.order_form_id')
                    ->where('order_form_number_filter.number', $this->filters['order_form_number']);
            });
        }

        if ($this->present('quote_number')) {
            // Rails: joins(order_form: :quote).where(quotes: {number:}).
            $orders->join('order_forms as order_form_quote_filter', 'order_form_quote_filter.id', '=', 'orders.order_form_id')
                ->join('quote_versions as quote_version_filter', 'quote_version_filter.id', '=', 'order_form_quote_filter.quote_version_id')
                ->join('quotes as quote_number_filter', 'quote_number_filter.id', '=', 'quote_version_filter.quote_id')
                ->where('quote_number_filter.number', $filters['quote_number']);
        }

        if ($this->present('owner_id')) {
            // Rails: where(quotes: {id: QuoteOwner.where(user_id:).select(:quote_id)}).
            $quoteIds = QuoteOwner::query()
                ->where('organization_id', $this->organization->id)
                ->where('user_id', $filters['owner_id'])
                ->pluck('quote_id');

            if ($quoteIds->isEmpty()) {
                $orders->whereRaw('1 = 0');
            } else {
                $orders->join('order_forms as order_form_owner_filter', 'order_form_owner_filter.id', '=', 'orders.order_form_id')
                    ->join('quote_versions as quote_version_owner_filter', 'quote_version_owner_filter.id', '=', 'order_form_owner_filter.quote_version_id')
                    ->whereIn('quote_version_owner_filter.quote_id', $quoteIds);
            }
        }

        $executedAtFrom = $this->parseDatetimeFilter($filters['executed_at_from'] ?? null);
        $executedAtTo = $this->parseDatetimeFilter($filters['executed_at_to'] ?? null);

        if ($executedAtFrom !== null) {
            $orders->where('orders.executed_at', '>=', $executedAtFrom);
        }

        if ($executedAtTo !== null) {
            $orders->where('orders.executed_at', '<=', $executedAtTo);
        }

        $result->orders = $this->paginate(
            $orders
                // Rails: apply_consistent_ordering (created_at desc, id asc).
                ->latest('orders.created_at')
                ->orderBy('orders.id'),
        );

        return $result;
    }

    /** Rails: `filters.key.present?` semantics — blank lists/strings skip. */
    private function present(string $key): bool
    {
        $value = $this->filters[$key] ?? null;

        if (is_array($value)) {
            return $value !== [];
        }

        return $value !== null && $value !== '';
    }

    /** Rails: the ransack search — number_cont. */
    private function withSearchTerm(Builder $scope): Builder
    {
        $term = $this->searchTerm;

        if ($term === null || mb_trim($term) === '') {
            return $scope;
        }

        $like = '%'.mb_strtolower(mb_trim($term)).'%';

        return $scope->whereRaw('LOWER(orders.number) LIKE ?', [$like]);
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

    /** Rails: `parse_datetime_filter` — ISO8601 strings, unparsed = skipped. */
    private function parseDatetimeFilter(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Facades\Date::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
