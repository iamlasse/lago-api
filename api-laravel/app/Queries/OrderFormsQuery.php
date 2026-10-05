<?php

declare(strict_types=1);

namespace App\Queries;

use Throwable;
use App\Models\OrderForm;
use App\Models\QuoteOwner;
use App\Models\QuoteVersion;
use App\Services\BaseResult;
use App\GraphQL\Support\Page;
use App\Services\BaseService;
use Illuminate\Support\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Port of Rails' OrderFormsQuery (app/queries/order_forms_query.rb).
 *
 * Filters: status, customer_id, number, quote_number, owner_id,
 * created_at_from/to, expires_at_from/to, plus the ransack search term on
 * the number (number_cont).
 *
 * TODO(port): Queries::OrderFormsQueryFiltersContract — the port is lenient
 * about filter shapes until the shared filter-contract port lands.
 */
class OrderFormsQuery extends BaseService
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
        $result = static::makeResult('order_forms');

        // Rails: base_scope = organization.order_forms.includes(:quote_version),
        // ransack(number_cont: search_term).
        $orderForms = OrderForm::query()
            ->select('order_forms.*')
            ->where('order_forms.organization_id', $this->organization->id)
            ->with('quoteVersion');

        $orderForms = $this->withSearchTerm($orderForms);

        $filters = $this->filters;

        if ($this->present('status')) {
            $orderForms->where('order_forms.status', $filters['status']);
        }

        if ($this->present('customer_id')) {
            $orderForms->where('order_forms.customer_id', $filters['customer_id']);
        }

        if ($this->present('number')) {
            $orderForms->where('order_forms.number', $filters['number']);
        }

        if ($this->present('quote_number')) {
            $orderForms
                ->join('quote_versions', 'quote_versions.id', '=', 'order_forms.quote_version_id')
                ->join('quotes', 'quotes.id', '=', 'quote_versions.quote_id')
                ->where('quotes.number', $filters['quote_number']);
        }

        if ($this->present('owner_id')) {
            // Rails: where(quote_version_id: QuoteVersion.where(
            //   quote_id: QuoteOwner.where(user_id:).select(:quote_id)).select(:id)).
            $quoteIds = QuoteOwner::query()
                ->where('organization_id', $this->organization->id)
                ->where('user_id', $filters['owner_id'])
                ->select('quote_owners.quote_id');

            $versionIds = QuoteVersion::query()
                ->whereIn('quote_versions.quote_id', $quoteIds)
                ->select('quote_versions.id');

            $orderForms->whereIn('order_forms.quote_version_id', $versionIds);
        }

        $createdAtFrom = $this->parseDatetimeFilter($filters['created_at_from'] ?? null);
        $createdAtTo = $this->parseDatetimeFilter($filters['created_at_to'] ?? null);
        $expiresAtFrom = $this->parseDatetimeFilter($filters['expires_at_from'] ?? null);
        $expiresAtTo = $this->parseDatetimeFilter($filters['expires_at_to'] ?? null);

        if ($createdAtFrom !== null) {
            $orderForms->where('order_forms.created_at', '>=', $createdAtFrom);
        }

        if ($createdAtTo !== null) {
            $orderForms->where('order_forms.created_at', '<=', $createdAtTo);
        }

        if ($expiresAtFrom !== null) {
            $orderForms->where('order_forms.expires_at', '>=', $expiresAtFrom);
        }

        if ($expiresAtTo !== null) {
            $orderForms->where('order_forms.expires_at', '<=', $expiresAtTo);
        }

        $result->order_forms = $this->paginate(
            $orderForms
                // Rails: apply_consistent_ordering (created_at desc, id asc).
                ->latest('order_forms.created_at')
                ->orderBy('order_forms.id'),
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
    private function withSearchTerm($scope): object
    {
        $term = $this->searchTerm;

        if ($term === null || mb_trim($term) === '') {
            return $scope;
        }

        $like = '%'.mb_strtolower(mb_trim($term)).'%';

        return $scope->whereRaw('LOWER(order_forms.number) LIKE ?', [$like]);
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

    /** Rails: `parse_datetime_filter` — ISO8601 strings, unparsed = skipped. */
    private function parseDatetimeFilter(mixed $value): ?Carbon
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
