<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Subscription;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Port of Rails' EventsQuery (app/queries/events_query.rb), including the
 * Queries::EventsQueryFiltersContract validation (dry-validation messages
 * reproduced verbatim).
 *
 * Not ported (dependencies do not exist):
 * - TODO(port): the ClickHouse paths (Clickhouse::EventsRaw /
 *   Clickhouse::EventsEnriched + the enriched filter) — the port always
 *   queries the Postgres `events` table, which is Rails' pg_event? branch.
 */
class EventsQuery extends BaseService
{
    /** Kaminari's default per page, used when the limit param is nil. */
    private const DEFAULT_PER_PAGE = 100;

    private ?\Carbon\CarbonInterface $memoTimestampFrom;

    private ?\Carbon\CarbonInterface $memoTimestampTo;

    public function __construct(
        private readonly Organization $organization,
        private readonly array $pagination = ['page' => null, 'limit' => null],
        private readonly array $filters = [],
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('events', 'event_model');

        $errors = $this->validateFilters();
        if ($errors !== []) {
            return $result->validationFailure($errors);
        }

        // Rails: event_model — Event on the pg path (the ClickHouse models
        // are TODO(port), see the docblock).
        $events = Event::query()
            ->where('organization_id', $this->organization->id);

        // Rails: paginate, then order (timestamp desc, transaction_id asc on
        // the pg path), then the filters — all lazily composed before the
        // query runs. Laravel's paginate() executes eagerly, so the filters
        // and the ordering are applied to the builder first (same SQL shape).
        $code = $this->filters['code'] ?? null;
        if ($code !== null && $code !== false) {
            $events->where('code', $code);
        }

        $externalSubscriptionId = $this->filters['external_subscription_id'] ?? null;
        if ($externalSubscriptionId !== null && $externalSubscriptionId !== false) {
            $events->where('external_subscription_id', $externalSubscriptionId);
        }

        $this->withTimestampRange($events);

        $events->orderByDesc('timestamp')->orderBy('transaction_id');

        $paginator = $this->paginate($events);

        $result->event_model = Event::class;
        $result->events = $paginator;

        return $result;
    }

    /**
     * Rails: Queries::EventsQueryFiltersContract.
     *
     * @return array<string, list<string>>
     */
    private function validateFilters(): array
    {
        $errors = [];

        $timestampFromStartedAt = $this->filters['timestamp_from_started_at'] ?? null;

        // `coercible.string, included_in?: %w[true false]` — the value must
        // coerce to a string AND be the literal "true"/"false" (a JSON
        // boolean fails the coercion, like Rails).
        if ($timestampFromStartedAt !== null) {
            $coerced = is_string($timestampFromStartedAt) ? $timestampFromStartedAt : null;

            if ($coerced === null || ! in_array($coerced, ['true', 'false'], true)) {
                $errors['timestamp_from_started_at'] = ['must be a string'];
            }
        }

        $fromStartedAt = $this->timestampFromStartedAt();

        if ($fromStartedAt && (($this->filters['timestamp_from'] ?? null) !== null && ($this->filters['timestamp_from'] ?? '') !== '')) {
            $errors['timestamp_from'] = ['cannot be used with timestamp_from_started_at'];
        }

        if ($fromStartedAt && (($this->filters['external_subscription_id'] ?? null) === null || ($this->filters['external_subscription_id'] ?? '') === '')) {
            $errors['external_subscription_id'] = ['required with timestamp_from_started_at'];
        }

        return $errors;
    }

    /** Rails: ActiveModel::Type::Boolean.new.cast(filters.timestamp_from_started_at). */
    private function timestampFromStartedAt(): bool
    {
        $value = $this->filters['timestamp_from_started_at'] ?? null;

        if ($value === null) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    private function withTimestampRange(\Illuminate\Database\Eloquent\Builder $scope): void
    {
        // Rails: `timestamp_from_started_at? && subscription` — the
        // subscription's started_at becomes the lower bound.
        if ($this->timestampFromStartedAt()) {
            $subscription = $this->subscription();
            if ($subscription !== null) {
                $scope->where('timestamp', '>=', $subscription->started_at);
            }
        } elseif (($this->filters['timestamp_from'] ?? null) !== null && ($this->filters['timestamp_from'] ?? '') !== '') {
            $scope->where('timestamp', '>=', $this->timestampFrom());
        }

        if (($this->filters['timestamp_to'] ?? null) !== null && ($this->filters['timestamp_to'] ?? '') !== '') {
            $scope->where('timestamp', '<=', $this->timestampTo());
        }
    }

    /**
     * Rails: the org's most recent subscription (terminated first) for the
     * external_subscription_id filter.
     */
    private function subscription(): ?Subscription
    {
        return $this->organization->subscriptions()
            ->orderByRaw('terminated_at DESC NULLS FIRST, started_at DESC')
            ->where('external_id', $this->filters['external_subscription_id'] ?? null)
            ->first();
    }

    private function timestampFrom(): \Carbon\CarbonInterface
    {
        return $this->memoTimestampFrom ??= $this->parseDatetimeFilter('timestamp_from');
    }

    private function timestampTo(): \Carbon\CarbonInterface
    {
        return $this->memoTimestampTo ??= $this->parseDatetimeFilter('timestamp_to');
    }

    /** Rails: parse_datetime_filter — Time.zone.parse / DateTime parsing. */
    private function parseDatetimeFilter(string $field): \Carbon\CarbonInterface
    {
        return \Carbon\Carbon::parse($this->filters[$field])->utc();
    }

    /**
     * Rails: `paginate` + kaminari — page/limit; nil (or blank) values fall
     * back to the defaults (page 1, limit else 100).
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

        return $scope
            ->paginate($perPage, ['*'], 'page', $page);
    }
}
