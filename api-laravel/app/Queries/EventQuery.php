<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Event;
use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;

/**
 * Port of Rails' EventQuery (app/queries/event_query.rb) — the single-event
 * lookup behind the `event` GraphQL root: scoped by transaction id (mandatory),
 * narrowed by external subscription id / code / millisecond timestamp.
 *
 * Not ported (dependencies do not exist):
 * - TODO(port): the ClickHouse path (Clickhouse::EventsRaw + the lossless
 *   millisecond timestamp filter) — the port always queries the Postgres
 *   `events` table, Rails' pg_event? branch.
 */
class EventQuery extends BaseService
{
    public function __construct(
        private readonly Organization $organization,
        private readonly array $filters = [],
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('event');

        $transactionId = $this->filters['transaction_id'] ?? null;

        if ($transactionId === null || $transactionId === '') {
            return $result->singleValidationFailure('value_is_mandatory', 'transaction_id');
        }

        $events = Event::query()
            ->where('organization_id', $this->organization->id)
            ->where('transaction_id', $transactionId);

        $externalSubscriptionId = $this->filters['external_subscription_id'] ?? null;
        if ($externalSubscriptionId !== null && $externalSubscriptionId !== '') {
            $events->where('external_subscription_id', $externalSubscriptionId);
        }

        $code = $this->filters['code'] ?? null;
        if ($code !== null && $code !== '') {
            $events->where('code', $code);
        }

        // Rails: with_timestamp is a no-op on the Postgres path (the column
        // holds microseconds a millisecond filter could not address).
        $result->event = $events->orderByDesc('created_at')->first();

        return $result;
    }
}
