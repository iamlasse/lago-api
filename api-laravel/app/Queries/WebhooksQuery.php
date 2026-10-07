<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Webhook;
use App\Enums\WebhookStatus;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Port of Rails' WebhooksQuery (app/queries/webhooks_query.rb) — the
 * webhook log of one endpoint, with the statuses/event_types/http_statuses/
 * date-range filters and the id/object_id search term.
 */
class WebhooksQuery extends BaseService
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
        $result = static::makeResult('webhooks');

        $webhooks = Webhook::query()
            ->where('organization_id', $this->organization->id)
            ->where('webhook_endpoint_id', $this->filters['webhook_endpoint_id'] ?? null);

        $webhooks = $this->withSearchTerm($webhooks);

        $webhooks = $this->applyFilters($webhooks);

        $result->webhooks = $this->paginate($webhooks);

        return $result;
    }

    /**
     * Rails: ransack search `m: or, id_cont / object_id_cont`.
     */
    private function withSearchTerm(Builder $webhooks): Builder
    {
        $searchTerm = $this->searchTerm !== null ? mb_trim($this->searchTerm) : '';

        if ($searchTerm === '') {
            return $webhooks;
        }

        return $webhooks->where(function (Builder $query) use ($searchTerm): void {
            $query->where('id', 'ILIKE', "%{$searchTerm}%")
                ->orWhere('object_id', 'ILIKE', "%{$searchTerm}%");
        });
    }

    private function applyFilters(Builder $webhooks): Builder
    {
        $statuses = $this->filters['statuses'] ?? null;
        if ($statuses !== null && $statuses !== []) {
            $webhooks->whereIn('status', array_filter(array_map(
                fn ($status): ?int => $status instanceof WebhookStatus
                    ? $status->value
                    : WebhookStatus::fromOption($status),
                (array) $statuses,
            )));
        }

        $eventTypes = $this->filters['event_types'] ?? null;
        if ($eventTypes !== null && $eventTypes !== []) {
            $webhooks->whereIn('webhook_type', (array) $eventTypes);
        }

        $fromDate = $this->filters['from_date'] ?? null;
        if ($fromDate !== null) {
            $webhooks->where('updated_at', '>=', $fromDate);
        }

        $toDate = $this->filters['to_date'] ?? null;
        if ($toDate !== null) {
            $webhooks->where('updated_at', '<=', $toDate);
        }

        $webhooks = $this->withHttpStatuses($webhooks);

        // Rails: paginate → order(updated_at: :desc, created_at: :desc).
        return $webhooks->latest('updated_at')->latest();
    }

    /**
     * Rails: with_http_statuses — exact "200", wildcard "2xx", ranges
     * "404-412"; a "timeout" entry matches http_status IS NULL on failed
     * webhooks. Ranges are OR-combined.
     */
    private function withHttpStatuses(Builder $webhooks): Builder
    {
        $statuses = array_map(
            fn ($status): string => mb_strtolower((string) $status),
            (array) ($this->filters['http_statuses'] ?? []),
        );

        if ($statuses === []) {
            return $webhooks;
        }

        $conditions = [];

        foreach ($statuses as $status) {
            $base = null;
            $end = null;

            if (preg_match('/\A\d{3}\z/', $status)) {
                $base = $end = (int) $status;
            } elseif (preg_match('/\A(\d)xx\z/i', $status, $m)) {
                $base = ((int) $m[1]) * 100;
                $end = $base + 99;
            } elseif (preg_match('/\A(\d{3})\s*-\s*(\d{3})\z/', $status, $m)) {
                $base = (int) $m[1];
                $end = (int) $m[2];
            }

            if ($base !== null) {
                $conditions[] = [$base, $end];
            }
        }

        if ($conditions === []) {
            return $webhooks;
        }

        return $webhooks->where(function (Builder $query) use ($conditions, $statuses): void {
            foreach ($conditions as [$base, $end]) {
                $query->orWhereBetween('http_status', [$base, $end]);
            }

            if (in_array('timeout', $statuses, true)) {
                $query->orWhere(function (Builder $q): void {
                    $q->whereNull('http_status')->where('status', WebhookStatus::Failed->value);
                });
            }
        });
    }

    /**
     * Rails: paginate (kaminari) — the ordering is applied before the
     * filters in Rails but they compose lazily; same SQL shape.
     */
    private function paginate(Builder $query): LengthAwarePaginator
    {
        $page = max(1, (int) ($this->pagination['page'] ?? 1));
        $limit = max(1, (int) ($this->pagination['limit'] ?? \App\GraphQL\Support\Page::DEFAULT_LIMIT));

        return $query->paginate(perPage: $limit, page: $page);
    }
}
