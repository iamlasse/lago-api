<?php

declare(strict_types=1);

namespace App\Services\Events;

use stdClass;
use Throwable;
use Carbon\Carbon;
use App\Models\Event;
use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Jobs\Events\PostProcessJob;

/**
 * Port of Rails' Events::CreateBatchService
 * (app/services/events/create_batch_service.rb) — the batch ingestion flow:
 * per-event validation (including the expression hook), then a single bulk
 * INSERT ... ON CONFLICT DO NOTHING RETURNING against
 * `index_unique_transaction_id` so duplicate transaction_ids — within the
 * payload or against stored events — are reported per index.
 *
 * Differences forced by PHP, noted for parity review:
 * - Rails' errors hash is keyed by event index; a PHP array with int keys
 *   would json_encode as a list, so the rendered errors object is built
 *   with string keys (same device as Queries\CustomersQuery).
 */
class CreateBatchService extends BaseService
{
    /** ENV.fetch("LAGO_EVENTS_BATCH_MAX_LENGTH", 100).to_i */
    private const MAX_LENGTH = 100;

    public function __construct(
        private readonly Organization $organization,
        private readonly array $eventsParams,
        private readonly int|float|string|null $timestamp,
        private readonly array $metadata = [],
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('events', 'errors');

        $eventsParams = $this->eventsParams['events'] ?? null;

        if ($eventsParams === null || $eventsParams === []) {
            return $result->singleValidationFailure('no_events', 'events');
        }

        if (count($eventsParams) > self::maxBatchLength()) {
            return $result->singleValidationFailure('too_many_events', 'events');
        }

        $this->validateEvents($eventsParams, $result);
        if ((array) $result->errors !== []) {
            return $result->validationFailure($this->indexedErrors((array) $result->errors));
        }

        $this->postValidateEvents($result);

        // Rails: `return result.validation_failure!(errors: result.errors)
        // if result.errors.present?` — re-checked after the insert (bulk
        // conflicts landed there).
        if ((array) $result->errors !== []) {
            return $result->validationFailure($this->indexedErrors((array) $result->errors));
        }

        return $result;
    }

    /** ENV-backed so deployments can raise the limit, like Rails' ENV.fetch. */
    private static function maxBatchLength(): int
    {
        $value = getenv('LAGO_EVENTS_BATCH_MAX_LENGTH');

        return $value === false || $value === '' ? self::MAX_LENGTH : (int) $value;
    }

    /** @param array<int, mixed> $eventsParams */
    private function validateEvents(array $eventsParams, BaseResult $result): void
    {
        $errors = [];
        $events = [];

        foreach ($eventsParams as $index => $eventParams) {
            if (! is_array($eventParams)) {
                continue;
            }

            $eventTimestamp = $this->parseTimestamp($eventParams['timestamp'] ?? null);
            if ($eventTimestamp === null) {
                // Rails: ArgumentError from BigDecimal -> {timestamp: ["invalid_format"]}.
                $errors[$index] = ['timestamp' => ['invalid_format']];

                continue;
            }

            $event = new Event();
            $event->organization_id = $this->organization->id;
            $event->code = $eventParams['code'] ?? null;
            $event->transaction_id = $eventParams['transaction_id'] ?? null;
            // external_contract_id is the v2 alias for external_subscription_id; an
            // explicit external_subscription_id wins. See Events\CreateService.
            $event->external_subscription_id = ($eventParams['external_subscription_id'] ?? null)
                ?: ($eventParams['external_contract_id'] ?? null);
            $event->properties = $eventParams['properties'] ?? [];
            $event->metadata = $this->metadata ?: [];
            $event->timestamp = $eventTimestamp;
            $event->precise_total_amount_cents = CreateService::sanitizePreciseAmount($eventParams['precise_total_amount_cents'] ?? null);

            $expressionResult = CalculateExpressionService::call(
                organization: $this->organization,
                event: $event,
            );
            if (! $expressionResult->success()) {
                $errors[$index] = $expressionResult->getError()->getMessage();
            }

            $events[] = $event;

            $validationMessages = $this->validationMessages($event);
            if ($validationMessages !== []) {
                $errors[$index] = $validationMessages;
            }
        }

        $result->events = $events;
        $result->errors = $errors;
    }

    private function postValidateEvents(BaseResult $result): void
    {
        if ($this->organization->postgresEventsStore()) {
            $this->bulkInsertEvents($result);
        }

        if ((array) $result->errors !== []) {
            return;
        }

        // Enqueued before producing to Kafka so that a failed enqueue leaves
        // nothing behind downstream either.
        $this->enqueuePostProcessJobs($result);

        // TODO(port): kafka raw-events producer (Events::KafkaProducerService —
        // M2 later).
    }

    /**
     * Rails: `Event.insert_all(records, unique_by: :index_unique_transaction_id,
     * returning: [:transaction_id, :id, :created_at, :updated_at])` inside a
     * transaction that ROLLS BACK when any event conflicts — so a bulk
     * conflict persists nothing, and the conflict is reported per index.
     */
    private function bulkInsertEvents(BaseResult $result): void
    {
        /** @var list<Event> $events */
        $events = (array) $result->events;
        if ($events === []) {
            return;
        }

        $now = now()->format('Y-m-d H:i:s.u');

        $columns = ['organization_id', 'transaction_id', 'code', 'external_subscription_id',
            'external_customer_id', 'subscription_id', 'customer_id', 'deleted_at',
            'properties', 'metadata', 'timestamp', 'precise_total_amount_cents'];

        $rows = [];
        $bindings = [];
        foreach ($events as $event) {
            $values = [
                $event->organization_id,
                $event->transaction_id,
                $event->code,
                $event->external_subscription_id,
                $event->external_customer_id,
                $event->subscription_id,
                $event->customer_id,
                $event->deleted_at,
                json_encode((array) ($event->properties ?? [])),
                json_encode((array) ($event->metadata ?? [])),
                $event->timestamp?->format('Y-m-d H:i:s.u'),
                $event->precise_total_amount_cents,
                $now,
                $now,
            ];

            foreach ($values as $value) {
                $bindings[] = $value;
            }

            $rows[] = '('.implode(', ', array_fill(0, count($values), '?')).')';
        }

        $sql = 'insert into "events" ('.implode(', ', array_map(
            fn (string $column): string => '"'.$column.'"',
            array_merge($columns, ['created_at', 'updated_at']),
        )).') values '.implode(', ', $rows)
            .' on conflict (organization_id, external_subscription_id, transaction_id) do nothing'
            .' returning transaction_id, id, created_at, updated_at';

        $rolledBack = false;

        try {
            DB::transaction(function () use ($sql, $bindings, $events, $result): void {
                $attributesPerTransactionId = [];
                foreach (DB::select($sql, $bindings) as $row) {
                    $attributesPerTransactionId[$row->transaction_id] = $row;
                }

                $errors = (array) $result->errors;
                foreach ($events as $index => $event) {
                    // We delete to ensure that any duplicate transaction_id in the
                    // input events_params are caught and reported as errors.
                    $attributes = $attributesPerTransactionId[$event->transaction_id] ?? null;
                    unset($attributesPerTransactionId[$event->transaction_id]);
                    if ($attributes !== null) {
                        // NOTE: even though we set id, created_at and updated_at here, the
                        // event is not considered persisted, like Rails' insert_all.
                        $event->id = $attributes->id;
                        $event->created_at = \Illuminate\Support\Facades\Date::parse($attributes->created_at);
                        $event->updated_at = \Illuminate\Support\Facades\Date::parse($attributes->updated_at);
                    } else {
                        $errors[$index] = ['transaction_id' => ['value_already_exist']];
                    }
                }

                $result->errors = $errors;

                if ($errors !== []) {
                    throw new BulkInsertRollback();
                }
            });
        } catch (BulkInsertRollback) {
            $rolledBack = true;
        }

        if ($rolledBack) {
            // The transaction rolled the rows back; drop the backfilled
            // attributes so the models read as non-persisted, like Rails.
            foreach ($events as $event) {
                $event->id = null;
                $event->created_at = null;
                $event->updated_at = null;
            }
        }
    }

    /** Rails: ApplicationJob.perform_all_later(jobs). */
    private function enqueuePostProcessJobs(BaseResult $result): void
    {
        /** @var list<Event> $events */
        $events = (array) $result->events;
        if ($events === []) {
            return;
        }

        try {
            foreach ($events as $event) {
                dispatch(new \App\Jobs\Events\PostProcessJob($event));
            }
        } catch (Throwable $exception) {
            // `perform_all_later` is a single bulk push, so one failure strands the
            // whole batch. Hard-deleted rather than discarded for the same reason
            // as in `Events\CreateService`.
            Event::whereKey(array_map(fn (Event $event): string => (string) $event->id, $events))->forceDelete();

            throw $exception;
        }
    }

    /**
     * Rails: `Time.zone.at(event_params[:timestamp] ? BigDecimal(event_params[:timestamp].to_s) : timestamp)`.
     */
    private function parseTimestamp(mixed $value): ?Carbon
    {
        if ($value === null || $value === false) {
            $value = $this->timestamp;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $decimal = mb_trim((string) $value);
        if (! preg_match('/\A[+-]?\d+(\.\d+)?\z/', $decimal)) {
            return null;
        }

        $negative = str_starts_with($decimal, '-');
        $digits = mb_ltrim($decimal, '+-');
        [$seconds, $fraction] = array_pad(explode('.', $digits, 2), 2, '0');

        $micro = (int) mb_str_pad(mb_substr($fraction, 0, 6), 6, '0');
        $datetime = \Illuminate\Support\Facades\Date::createFromTimestampUTC((int) $seconds);
        if ($micro > 0) {
            $datetime->addMicroseconds($negative ? -$micro : $micro);
        }

        return $datetime;
    }

    /**
     * Rails: `event.errors.messages` — the presence validations on
     * transaction_id/code. Lago's en.yml overrides the ActiveRecord "blank"
     * message with the error code "value_is_mandatory"
     * (config/locales/en.yml:7), same mapping as the single-event path.
     *
     * @return array<string, list<string>>
     */
    private function validationMessages(Event $event): array
    {
        $messages = [];

        if (($event->transaction_id ?? '') === '') {
            $messages['transaction_id'] = ['value_is_mandatory'];
        }

        if (($event->code ?? '') === '') {
            $messages['code'] = ['value_is_mandatory'];
        }

        return $messages;
    }

    /**
     * Index-keyed errors wrapped as a string-keyed object so the JSON renders
     * Rails' {"1": {...}} instead of a list.
     *
     * @param  array<int|string, mixed>  $errors
     */
    private function indexedErrors(array $errors): object
    {
        $object = new stdClass();

        foreach ($errors as $index => $message) {
            $object->{(string) $index} = $message;
        }

        return $object;
    }
}
