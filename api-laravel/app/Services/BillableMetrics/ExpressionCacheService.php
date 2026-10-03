<?php

declare(strict_types=1);

namespace App\Services\BillableMetrics;

use Illuminate\Support\Facades\Cache;

/**
 * Port of Rails' BillableMetrics::ExpressionCacheService
 * (app/services/billable_metrics/expression_cache_service.rb): caches the
 * [field_name, expression] pair of a billable metric under
 * `expression/<version>/<organization_id>/<code>` — written with no TTL,
 * exactly like Rails' CacheService without `expires_in`.
 *
 * Invalidation (Rails `ExpressionCacheService.expire_cache`) happens in the
 * billable metrics create/update/destroy services — wired there.
 */
class ExpressionCacheService
{
    public const CACHE_KEY_VERSION = '1';

    /**
     * Port of CacheService#call: read the cached pair or compute and store it.
     *
     * @param  callable(): array{0: ?string, 1: ?string}  $compute
     * @return array{0: ?string, 1: ?string}
     */
    public static function call(string $organizationId, string $billableMetricCode, callable $compute): array
    {
        $cacheKey = self::cacheKey($organizationId, $billableMetricCode);

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $value = $compute();

        Cache::put($cacheKey, $value);

        return $value;
    }

    /** Port of CacheService#expire_cache (class-level `self.expire_cache`). */
    public static function expireCache(string $organizationId, string $billableMetricCode): void
    {
        Cache::delete(self::cacheKey($organizationId, $billableMetricCode));
    }

    private static function cacheKey(string $organizationId, string $billableMetricCode): string
    {
        return implode('/', array_filter([
            'expression',
            self::CACHE_KEY_VERSION,
            $organizationId,
            $billableMetricCode,
        ]));
    }
}
