<?php

declare(strict_types=1);

namespace App\Models\Analytics;

use DateInterval;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

/**
 * Port of Rails' Analytics::Base (app/models/analytics/base.rb).
 *
 * IMPORTANT Rails fact the port keeps faithful: these analytics models run
 * their raw SQL on the PRIMARY Postgres connection
 * (`ActiveRecord::Base.connection.exec_query`), NOT on ClickHouse — the
 * generate_series / date_trunc / jsonb_agg queries join the organizations,
 * invoices, fees, credit_notes tables. They sit behind the premium-gated
 * /analytics endpoints; the ClickHouse-direct path is the events store
 * (Events::Stores::ClickhouseStore).
 *
 * Results are cached (4h, Rails.cache) under a per-day cache key, with a
 * per-customer version token appended so every cached variant of a
 * customer is invalidated at once (VERSION_CACHE_EXPIRATION = 1 day).
 */
abstract class Base
{
    public const VERSION_CACHE_EXPIRATION = 86400; // 1.day

    /**
     * Port of `query(organization_id, **args)` — the raw SQL (Rails:
     * sanitize_sql with named binds embedded as quoted literals).
     *
     * @param  array<string, mixed>  $args
     */
    abstract public static function query(string $organizationId, array $args = []): string;

    /**
     * Port of `cache_key(organization_id, **args)`.
     *
     * @param  array<string, mixed>  $args
     */
    abstract public static function cacheKey(string $organizationId, array $args = []): string;

    /**
     * Port of `find_all_by(organization_id, **args)` — cache-wrapped query
     * execution.
     *
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    public static function findAllBy(string $organizationId, array $args = []): array
    {
        if (($args['expire_cache'] ?? null) === true && ($args['external_customer_id'] ?? null) !== null) {
            static::expireCacheForCustomer($organizationId, (string) $args['external_customer_id']);
        }

        $key = static::versionedCacheKey($organizationId, $args);

        /** @var list<array<string, mixed>> */
        return Cache::remember($key, static::cacheExpiration(), function () use ($organizationId, $args): array {
            $rows = DB::select(static::query($organizationId, $args));

            return array_map(static fn (object $row): array => (array) $row, $rows);
        });
    }

    /** Rails: `cache_expiration` — 4.hours. */
    public static function cacheExpiration(): DateInterval
    {
        return DateInterval::createFromDateString('4 hours');
    }

    /**
     * Appends a per-customer version token to the cache key so all the
     * cached variants of a customer can be invalidated at once by bumping a
     * single token. Org-level keys (no external_customer_id) are untouched.
     *
     * @param  array<string, mixed>  $args
     */
    public static function versionedCacheKey(string $organizationId, array $args): string
    {
        $key = static::cacheKey($organizationId, $args);

        if (($args['external_customer_id'] ?? null) === null || $args['external_customer_id'] === '') {
            return $key;
        }

        return $key.'/'.static::cacheVersion($organizationId, (string) $args['external_customer_id']);
    }

    /**
     * Wall-clock version token, seeded with the current timestamp when
     * absent (a regenerated token is always greater than any token a
     * still-cached data key was written with, so a lost token can only ever
     * cause a cold recompute, never a stale hit).
     */
    public static function cacheVersion(string $organizationId, string $externalCustomerId): string
    {
        $key = static::cacheVersionKey($organizationId, $externalCustomerId);

        $version = Cache::get($key);

        if (is_string($version) && $version !== '') {
            return $version;
        }

        $version = (string) Carbon::now()->getTimestamp();
        Cache::put($key, $version, DateInterval::createFromDateString('1 day'));

        return $version;
    }

    public static function expireCacheForCustomer(string $organizationId, string $externalCustomerId): void
    {
        Cache::put(
            static::cacheVersionKey($organizationId, $externalCustomerId),
            (string) Carbon::now()->getTimestamp(),
            DateInterval::createFromDateString('1 day'),
        );
    }

    /** `#{name}/cache-version/#{organization_id}/#{external_customer_id}`. */
    public static function cacheVersionKey(string $organizationId, string $externalCustomerId): string
    {
        return static::class.'/cache-version/'.$organizationId.'/'.$externalCustomerId;
    }

    /**
     * Port of `ActiveRecord::Base.sanitize_sql([sql, binds])` — the :named
     * binds are interpolated as QUOTED literals (integers bare, strings
     * single-quoted), matching Rails' value quoting.
     *
     * @param  array<string, mixed>  $binds
     */
    protected static function sanitizeSql(string $sql, array $binds): string
    {
        // Longest names first so :organization_id is never shadowed by a
        // shorter prefix.
        $names = array_keys($binds);
        usort($names, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($names as $name) {
            $value = $binds[$name];

            $literal = match (true) {
                is_int($value) => (string) $value,
                is_bool($value) => $value ? 'TRUE' : 'FALSE',
                $value === null => 'NULL',
                default => static::quoteString((string) $value),
            };

            $sql = preg_replace('/:'.$name.'\b/', $literal, $sql) ?? $sql;
        }

        return $sql;
    }

    /** PostgreSQL-style single-quote literal (doubles embedded '). */
    protected static function quoteString(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
