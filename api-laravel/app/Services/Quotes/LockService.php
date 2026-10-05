<?php

declare(strict_types=1);

namespace App\Services\Quotes;

use App\Models\Quote;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use App\Services\Failures\LockAcquisitionFailure;

/**
 * Port of Rails' Quotes::LockService (app/services/quotes/lock_service.rb,
 * over BaseLockService).
 *
 * Acquires a PostgreSQL advisory lock scoped to a quote to serialize
 * mutations across the whole quote aggregate (quote, quote versions, order
 * forms and orders).
 *
 * The lock is reentrant: cascade calls that re-enter the same quote lock
 * (approve -> create order form, expire/void -> void version, clone -> void
 * version) yield immediately within the already-held transaction instead of
 * re-acquiring — transaction-scoped advisory locks stack inside the same
 * transaction, which is exactly Rails' reentrancy contract.
 *
 * The lock key string matches Rails' exactly ("quote-<id>") and is hashed in
 * Postgres (hashtext), so both stacks would take the same lock. Exceeding
 * the lock timeout raises LockAcquisitionFailure (Rails:
 * BaseLockService::FailedToAcquireLock).
 */
final class LockService
{
    /** Rails: BaseLockService::ACQUIRE_LOCK_TIMEOUT = 5.seconds. */
    public const ACQUIRE_LOCK_TIMEOUT = '5s';

    /**
     * Runs the body while holding the quote lock. Expects to be inside a
     * transaction (every caller opens one around the aggregate mutation);
     * when none is open, one is opened here.
     *
     * @param  callable(): mixed  $body
     */
    public static function call(Quote $quote, callable $body): mixed
    {
        $connection = DB::connection();

        $run = function () use ($connection, $quote, $body): mixed {
            try {
                $connection->statement("SET LOCAL lock_timeout = '".self::ACQUIRE_LOCK_TIMEOUT."'");
                $connection->select('SELECT pg_advisory_xact_lock(hashtext(?))', [self::lockKey($quote)]);
            } catch (QueryException $e) {
                $sqlstate = $e->errorInfo[0] ?? '';

                if ($sqlstate === '55P03' || str_contains($e->getMessage(), '55P03')) {
                    throw new LockAcquisitionFailure(
                        "Failed to acquire lock quote-{$quote->id}",
                    );
                }

                throw $e;
            }

            return $body();
        };

        if ($connection->transactionLevel() > 0) {
            return $run();
        }

        return $connection->transaction($run);
    }

    /** Rails: #lock_key — "quote-#{quote.id}". */
    public static function lockKey(Quote $quote): string
    {
        return "quote-{$quote->id}";
    }
}
