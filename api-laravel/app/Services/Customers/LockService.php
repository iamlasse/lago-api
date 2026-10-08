<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Models\Customer;
use App\Services\BaseResult;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use App\Services\Failures\LockAcquisitionFailure;

/**
 * Port of Rails' Customers::LockService (app/services/customers/
 * lock_service.rb, over BaseLockService) — acquires a PostgreSQL advisory
 * lock scoped to a customer and a scope, to prevent concurrent operations of
 * the same kind on that customer. Each scope maps to an independent lock, so
 * operations in different scopes never block one another.
 *
 * The lock key string matches Rails' exactly
 * ("customer-<id>-<scope>") and is hashed in Postgres (hashtext). Exceeding
 * the lock timeout raises LockAcquisitionFailure
 * (Rails: BaseLockService::FailedToAcquireLock).
 */
final class LockService
{
    /** Rails: BaseLockService::ACQUIRE_LOCK_TIMEOUT = 5.seconds. */
    public const string ACQUIRE_LOCK_TIMEOUT = '5s';

    /** Rails: VALID_SCOPES. */
    public const array VALID_SCOPES = [
        'prepaid_credit',
        'payment_method',
        'credit_note',
        'coupon',
        'billing_schedule',
    ];

    /**
     * Runs the body while holding the customer lock. Rails opens a
     * transaction around the lock by default (`transaction: true`).
     *
     * @param  'prepaid_credit'|'payment_method'|'credit_note'|'coupon'|'billing_schedule'  $scope
     * @param  callable(): mixed  $body
     */
    public static function call(
        Customer $customer,
        string $scope,
        callable $body,
        string $timeoutSeconds = self::ACQUIRE_LOCK_TIMEOUT,
        bool $transaction = true,
    ): mixed {
        self::validateScope($scope);

        $connection = DB::connection();
        $lockKey = self::lockKey($customer, $scope);

        // Rails' with_advisory_lock timeout_seconds: 0 means a single TRY
        // (no wait); a positive timeout maps to lock_timeout + blocking
        // acquire. PG's lock_timeout = 0 means wait FOREVER, so 0 must never
        // route through lock_timeout.
        $run = function () use ($connection, $lockKey, $timeoutSeconds, $body): mixed {
            try {
                if (in_array($timeoutSeconds, ['0', '0s', '0 sec'], true)) {
                    $acquired = $connection->selectOne(
                        'SELECT pg_try_advisory_xact_lock(hashtext(?)) AS acquired',
                        [$lockKey],
                    );

                    if (! $acquired->acquired) {
                        throw new LockAcquisitionFailure(
                            new BaseResult([]),
                            'lock_acquisition_failed',
                            "Failed to acquire lock {$lockKey}",
                        );
                    }

                    return $body();
                }

                $connection->statement("SET LOCAL lock_timeout = '{$timeoutSeconds}'");
                $connection->select('SELECT pg_advisory_xact_lock(hashtext(?))', [$lockKey]);
            } catch (QueryException $e) {
                $sqlstate = $e->errorInfo[0] ?? '';

                if ($sqlstate === '55P03' || str_contains($e->getMessage(), '55P03')) {
                    throw new LockAcquisitionFailure(
                        new BaseResult([]),
                        'lock_acquisition_failed',
                        "Failed to acquire lock {$lockKey}",
                    );
                }

                throw $e;
            }

            return $body();
        };

        if (! $transaction) {
            return $run();
        }

        if ($connection->transactionLevel() > 0) {
            return $run();
        }

        return $connection->transaction($run);
    }

    /** Rails: `#lock_key` — "customer-#{customer.id}-#{scope}". */
    public static function lockKey(Customer $customer, string $scope): string
    {
        return "customer-{$customer->id}-{$scope}";
    }

    /** Rails: `#validate_scope!` (private). */
    private static function validateScope(string $scope): void
    {
        if (! in_array($scope, self::VALID_SCOPES, true)) {
            throw new InvalidArgumentException(
                'Invalid scope: '.$scope.'. Valid scopes are: '.implode(', ', self::VALID_SCOPES),
            );
        }
    }
}
