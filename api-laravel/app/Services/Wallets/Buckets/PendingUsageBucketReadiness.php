<?php

declare(strict_types=1);

namespace App\Services\Wallets\Buckets;

/**
 * The default UsageBucketReadiness until Clickhouse::UsageBucket lands —
 * reports every bucket as caught up, which collapses the refresh's
 * bucket wait to a no-op (the refresh runs immediately, matching the
 * pre-bucket behavior the sweep already covers).
 *
 * TODO(port): replace with the real watermark read —
 * `Clickhouse::UsageBucket.where(organization_id:, subscription_id:)
 * .where("toUnixTimestamp64Milli(last_ingested_at) >= ?", watermark_ms)
 * .exists?` — and swap the container binding.
 */
final class PendingUsageBucketReadiness implements UsageBucketReadiness
{
    public function caughtUp(string $organizationId, string $subscriptionId, int $watermarkMs): bool
    {
        return true;
    }
}
