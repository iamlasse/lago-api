<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Exceptions\SequenceException;
use Illuminate\Database\Eloquent\Attributes\Scope;

/**
 * Port of Rails' Sequenced concern (app/models/concerns/sequenced.rb).
 *
 * Assigns `sequential_id` inside an open transaction, guarded by a
 * transaction-scoped pg advisory lock so concurrent inserts cannot collide:
 *
 *   SET LOCAL lock_timeout = '10s';
 *   SELECT pg_advisory_xact_lock(hashtext('<lock_key>_lock'));
 *   sequential_id = scope.max(sequential_id) + 1
 *
 * The hash is computed in Postgres (hashtext), never in PHP, so lock keys are
 * identical to the ones Rails takes. Exceeding lock_timeout raises a
 * retryable SequenceException (SQLSTATE 55P03), like Rails' SequenceError.
 *
 * @mixin Model
 */
trait Sequenced
{
    public static function bootSequenced(): void
    {
        static::saving(function ($model): void {
            if ($model->sequential_id === null && $model->shouldAssignSequentialId()) {
                $model->sequential_id = $model->generateSequentialId();
            }
        });
    }

    /**
     * Port of `should_assign_sequential_id?` — override in the model
     * (Rails' Invoice gates the assignment on status_changed_to_finalized?,
     * so a draft keeps its NULL sequential_id until it is finalized).
     */
    protected function shouldAssignSequentialId(): bool
    {
        return true;
    }

    #[Scope]
    protected function withSequentialId(Builder $query): Builder
    {
        return $query->whereNotNull('sequential_id');
    }

    /**
     * Scope the max(sequential_id) query — port of the `sequenced scope:`
     * lambda. Override in the model, e.g.:
     *   return $this->customer->invoices()->getQuery();
     */
    protected function sequenceScope(): Builder
    {
        return static::query();
    }

    /**
     * Lock key override — port of `sequenced lock_key:`. Defaults to the
     * class name in Rails' underscore form + "_lock" (e.g. Invoice →
     * "invoice_lock").
     */
    protected function sequencedLockKey(): ?string
    {
        return null;
    }

    protected function generateSequentialId(): int
    {
        $connection = $this->getConnection();

        if (DB::connection($connection->getName())->transactionLevel() === 0) {
            throw new SequenceException('must be called inside a transaction');
        }

        $lockKey = ($this->sequencedLockKey() ?? Str::snake(class_basename(static::class))).'_lock';

        try {
            $connection->statement("SET LOCAL lock_timeout = '10s'");
            $connection->select('SELECT pg_advisory_xact_lock(hashtext(?))', [$lockKey]);
        } catch (QueryException $e) {
            $sqlstate = $e->errorInfo[0] ?? '';
            if ($sqlstate === '55P03' || str_contains($e->getMessage(), '55P03')) {
                throw new SequenceException('Unable to acquire lock on the database', 0, $e);
            }

            throw $e;
        }

        $max = (int) ($this->sequenceScope()->max('sequential_id') ?? 0);

        return $max + 1;
    }
}
