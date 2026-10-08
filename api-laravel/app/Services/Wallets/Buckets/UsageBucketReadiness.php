<?php

declare(strict_types=1);

namespace App\Services\Wallets\Buckets;

/**
 * The bucket catch-up seam behind Wallets\RealtimeRefreshService — the
 * port of the `Clickhouse::UsageBucket` watermark query in Rails'
 * `poll_buckets` (app/services/wallets/realtime_refresh_service.rb).
 *
 * TODO(port): Clickhouse::UsageBucket (the RisingWave -> ClickHouse usage
 * bucket sink is not landed). PendingUsageBucketReadiness is bound until
 * then; see app/Providers/AppServiceProvider.php.
 */
interface UsageBucketReadiness
{
    /**
     * Rails: any row version at the watermark proves the epoch landed —
     * `toUnixTimestamp64Milli(last_ingested_at) >= watermark_ms`, scoped to
     * (organization_id, subscription_id), unscoped and uncached.
     */
    public function caughtUp(string $organizationId, string $subscriptionId, int $watermarkMs): bool;
}
