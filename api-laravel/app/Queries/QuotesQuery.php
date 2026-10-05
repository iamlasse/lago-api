<?php

declare(strict_types=1);

namespace App\Queries;

use Throwable;
use App\Models\Quote;
use App\Models\Customer;
use App\Models\QuoteOwner;
use App\Models\QuoteVersion;
use App\Services\BaseResult;
use App\GraphQL\Support\Page;
use App\Services\BaseService;
use Illuminate\Support\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Port of Rails' QuotesQuery (app/queries/quotes_query.rb).
 *
 * Filters (Rails: customers, external_customer_ids, numbers, statuses,
 * from_date, to_date, owners, order_types — scalar values and lists are both
 * accepted): the status filter runs on the quote's CURRENT (latest) version,
 * which is what a reader means by "the quote's status".
 *
 * TODO(port): Queries::QuotesQueryFiltersContract — the port is lenient
 * about filter shapes until the shared filter-contract port lands.
 */
class QuotesQuery extends BaseService
{
    public function __construct(
        private readonly object $organization,
        private readonly array $pagination = ['page' => null, 'limit' => null],
        private readonly array $filters = [],
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('quotes');

        $quotes = Quote::query()
            ->select('quotes.*')
            ->where('quotes.organization_id', $this->organization->id);

        $filters = $this->filters;

        if ($this->present('customers')) {
            $quotes->whereIn('quotes.customer_id', (array) $filters['customers']);
        }

        if ($this->present('external_customer_ids')) {
            $quotes->whereIn('quotes.customer_id', Customer::query()
                ->where('organization_id', $this->organization->id)
                ->whereIn('external_id', (array) $filters['external_customer_ids'])
                ->select('customers.id'));
        }

        if ($this->present('numbers')) {
            $quotes->whereIn('quotes.number', (array) $filters['numbers']);
        }

        if ($this->present('statuses')) {
            // check status of the current (latest) version.
            $quoteIds = QuoteVersion::query()
                ->select('quote_versions.quote_id')
                ->where('quote_versions.organization_id', $this->organization->id)
                ->whereIn('quote_versions.status', (array) $filters['statuses'])
                ->whereRaw('sequential_id = (SELECT MAX(sequential_id) FROM quote_versions qv WHERE qv.quote_id = quote_versions.quote_id)');

            $quotes->whereIn('quotes.id', $quoteIds);
        }

        $fromDate = $this->parseDateFilter($filters['from_date'] ?? null);
        $toDate = $this->parseDateFilter($filters['to_date'] ?? null);

        if ($fromDate !== null) {
            $quotes->where('quotes.created_at', '>=', $fromDate);
        }

        if ($toDate !== null) {
            // Rails: where(created_at: ..to_date) — the Date upper bound casts
            // to the beginning of that day (Rails' AR date-range semantics).
            $quotes->where('quotes.created_at', '<=', $toDate->startOfDay());
        }

        if ($this->present('owners')) {
            $quoteIds = QuoteOwner::query()
                ->where('organization_id', $this->organization->id)
                ->whereIn('user_id', (array) $filters['owners'])
                ->select('quote_owners.quote_id');

            $quotes->whereIn('quotes.id', $quoteIds);
        }

        if ($this->present('order_types')) {
            $quotes->whereIn('quotes.order_type', (array) $filters['order_types']);
        }

        // Rails: quotes = quotes.order(created_at: :desc), then paginate.
        $result->quotes = $this->paginate(
            $quotes->latest('quotes.created_at'),
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

    /**
     * Rails: `paginate` — kaminari page/per; nil page/limit fall back to the
     * kaminari defaults (page 1, 25 per page) through Page::normalize.
     */
    private function paginate($scope): LengthAwarePaginator
    {
        [$page, $limit] = Page::normalizePageAndLimit(
            is_numeric($this->pagination['page'] ?? null) ? (int) $this->pagination['page'] : null,
            is_numeric($this->pagination['limit'] ?? null) ? (int) $this->pagination['limit'] : null,
        );

        return $scope->paginate(perPage: $limit, page: $page);
    }

    /** Rails: `parse_date_filter` — ISO8601 dates, unparsed = skipped. */
    private function parseDateFilter(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
