<?php

declare(strict_types=1);

namespace App\Services\Credits;

use Illuminate\Support\Facades\DB;

/**
 * Advisory lock used while applying coupons for a customer.
 *
 * TODO(port): Rails uses Customers::LockService (app/services/customers/
 * lock_service.rb, BaseLockService + with_advisory_lock!) — that namespace
 * is owned by another slice; move this behind it when it lands. The lock
 * key string matches Rails' exactly ("customer-<id>-<scope>") and is hashed
 * in Postgres (hashtext), so both stacks would take the same lock.
 */
final class CouponLock
{
    /** @var array<string, true> */
    private static array $held = [];

    /**
     * @param  callable(): mixed  $callback
     */
    public static function withLock(object $customer, string $scope, callable $callback): mixed
    {
        $key = self::key($customer, $scope);

        DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', [$key]);
        self::$held[$key] = true;

        try {
            return $callback();
        } finally {
            unset(self::$held[$key]);
        }
    }

    /** Port of BaseLockService#locked? — same-transaction check. */
    public static function held(object $customer, string $scope): bool
    {
        return isset(self::$held[self::key($customer, $scope)]);
    }

    private static function key(object $customer, string $scope): string
    {
        return "customer-{$customer->id}-{$scope}";
    }
}
