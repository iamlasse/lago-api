<?php

declare(strict_types=1);

namespace App\Services\Events;

use Throwable;
use Carbon\Carbon;
use App\Models\Event;
use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Jobs\Events\PostProcessJob;
use Illuminate\Database\QueryException;

/**
 * Port of Rails' Events::CreateService (app/services/events/create_service.rb)
 * — the single-event ingestion flow: timestamp parsing, the expression
 * evaluation hook, persistence, and the PostProcessJob enqueue.
 *
 * Deduplication on transaction_id is enforced by the frozen schema's
 * `index_unique_transaction_id` (organization_id, external_subscription_id,
 * transaction_id); a violation is rescued into the `value_already_exist`
 * validation failure, like Rails' RecordNotUnique rescue.
 */
class CreateService extends BaseService
{
    public function __construct(
        private readonly Organization $organization,
        private readonly array $params,
        private readonly int|float|string|null $timestamp,
        private readonly array $metadata = [],
    ) {
        parent::__construct();
    }

    /**
     * Rails' ActiveRecord decimal type casts any non-numeric value ("asdfa")
     * to 0 on assignment — mirrored here because the BcNumeric cast is not
     * used on this column (see Event's docblock).
     */
    public static function sanitizePreciseAmount(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $decimal = is_scalar($value) ? mb_trim((string) $value) : '';

        return preg_match('/\A[+-]?\d+(\.\d+)?\z/', $decimal) === 1 ? $decimal : '0';
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('event');

        $eventTimestamp = $this->parseTimestamp();
        if ($eventTimestamp === null) {
            return $result->singleValidationFailure('invalid_format', 'timestamp');
        }

        $event = new Event();
        $event->organization_id = $this->organization->id;
        $event->code = $this->params['code'] ?? null;
        $event->transaction_id = $this->params['transaction_id'] ?? null;
        // external_contract_id is the v2 alias: a catalog org addresses its
        // agreement by contract id, a legacy org by subscription id. The
        // event always stores it under external_subscription_id; an explicit
        // external_subscription_id wins.
        $event->external_subscription_id = ($this->params['external_subscription_id'] ?? null)
            ?: ($this->params['external_contract_id'] ?? null);
        $event->properties = $this->params['properties'] ?? [];
        $event->metadata = $this->metadata ?: [];
        $event->timestamp = $eventTimestamp;
        $event->precise_total_amount_cents = self::sanitizePreciseAmount($this->params['precise_total_amount_cents'] ?? null);

        $expressionResult = CalculateExpressionService::call(
            organization: $this->organization,
            event: $event,
        );
        if (! $expressionResult->success()) {
            // Rails: result.validation_failure!(errors: expression_result.error.message)
            return $result->validationFailure($expressionResult->getError()->getMessage());
        }

        // Rails: event.save! — RecordInvalid -> record validation failure,
        // RecordNotUnique -> value_already_exist. The save runs in a
        // (nested) transaction so a caught 23505 rolls back to the
        // savepoint instead of aborting the surrounding transaction block.
        try {
            DB::transaction(function () use ($event): void {
                $this->assertValid($event);
                $event->save();
            });
        } catch (EventValidation $exception) {
            return $result->validationFailure($exception->messages);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) === '23505') {
                return $result->singleValidationFailure('value_already_exist', 'transaction_id');
            }

            throw $exception;
        }

        $result->event = $event;

        // Enqueued before producing to Kafka so that a failed enqueue leaves
        // nothing behind downstream either.
        $this->enqueuePostProcess($event);

        // TODO(port): kafka raw-events producer (Events::KafkaProducerService —
        // M2 later; enqueued AFTER the post-process job, per Rails).

        return $result;
    }

    /**
     * Rails: `Time.zone.at(params[:timestamp] ? BigDecimal(params[:timestamp].to_s) : timestamp)`
     * — parses the timestamp through a decimal (never a float) so the
     * received precision survives; ArgumentError -> nil -> invalid_format.
     */
    private function parseTimestamp(): ?Carbon
    {
        $value = array_key_exists('timestamp', $this->params)
            && $this->params['timestamp'] !== null
            && $this->params['timestamp'] !== false
                ? $this->params['timestamp']
                : $this->timestamp;

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
        $datetime = Carbon::createFromTimestampUTC((int) $seconds);
        if ($micro > 0) {
            $datetime->addMicroseconds($negative ? -$micro : $micro);
        }

        return $datetime;
    }

    /**
     * Rails: Event's presence validations raise RecordInvalid from save! —
     * reproduced as the same validation messages hash.
     *
     * @throws EventValidation
     */
    private function assertValid(Event $event): void
    {
        $messages = [];

        if (($event->transaction_id ?? '') === '') {
            $messages['transaction_id'] = ["can't be blank"];
        }

        if (($event->code ?? '') === '') {
            $messages['code'] = ["can't be blank"];
        }

        if ($messages !== []) {
            throw new EventValidation($messages);
        }
    }

    private function enqueuePostProcess(Event $event): void
    {
        try {
            PostProcessJob::dispatch($event);
        } catch (Throwable $exception) {
            // Hard-deleted rather than discarded: `index_unique_transaction_id`
            // carries no `deleted_at` predicate, so a discarded event would keep
            // refusing the caller's retry.
            $event->forceDelete();

            throw $exception;
        }
    }
}
