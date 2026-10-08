<?php

declare(strict_types=1);

namespace App\Services\Wallets;

use App\Models\Customer;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\Log;
use App\Support\Metrics\RealtimeUsageMetrics;
use App\Services\Customers\RefreshWalletsService;
use App\Services\Wallets\Buckets\UsageBucketReadiness;

/**
 * Port of Rails' Wallets::RealtimeRefreshService
 * (app/services/wallets/realtime_refresh_service.rb) — the inline refresh
 * the wallet refresh triggers consumer runs per customer.
 *
 * wallet_codes carries the events' targeting intent
 * (properties.target_wallet_code): it forces the refresh, it does not
 * narrow it — the allocation cascade makes wallets interdependent.
 */
final class RealtimeRefreshService extends BaseService
{
    /**
     * Trigger and bucket upsert are two sinks of the same RisingWave
     * epoch, unordered between them: without the wait a fast consumer
     * reads the previous epoch's usage.
     */
    public const BUCKET_WAIT_TIMEOUT_SECONDS = 5;

    public const BUCKET_WAIT_INTERVAL_SECONDS = 0.1;

    public const STALE_WATERMARK_CUTOFF_SECONDS = 30;

    /**
     * @param  array<string, int>  $expectedIngestedAt  subscription_id => epoch millis watermark
     * @param  list<string>  $walletCodes
     */
    public function __construct(
        private readonly string $organizationId,
        private readonly string $customerId,
        private readonly array $walletCodes = [],
        private readonly array $expectedIngestedAt = [],
        private readonly ?UsageBucketReadiness $bucketReadiness = null,
        private readonly ?RealtimeUsageMetrics $metrics = null,
    ) {
        parent::__construct();
    }

    /**
     * Port of `Result = BaseResult[:wallets, :reason]` — `reason` carries
     * the skip symbol ('stale_watermark' / 'bucket_wait_timeout') when the
     * service walks away from the customer.
     */
    public function execute(): BaseResult
    {
        $result = self::makeResult('wallets', 'reason');

        $result->wallets = [];

        $customer = Customer::query()
            ->where('id', $this->customerId)
            ->where('organization_id', $this->organizationId)
            ->first();

        if ($customer === null) {
            return $result;
        }

        if (! $customer->wallets()->active()->exists()) {
            return $result;
        }

        // Refreshing on buckets that have not caught up writes a stale
        // balance and clears awaiting_wallet_refresh, the flag the sweep
        // selects on: nothing would correct it after.
        $waitReason = $this->waitForBuckets();

        if ($waitReason !== null) {
            $result->reason = $waitReason;

            return $result;
        }

        if ($this->walletCodes !== []
            && $customer->wallets()->active()->whereIn('code', $this->walletCodes)->doesntExist()) {
            Log::warning(sprintf(
                '[wallets] realtime refresh targeted unknown wallet codes customer_id=%s codes=%s',
                $customer->id,
                json_encode($this->walletCodes),
            ));
        }

        $startedAt = now();
        $refreshResult = RefreshWalletsService::call(customer: $customer);
        $this->metrics()->refreshDuration($startedAt->diffInSeconds(now()));

        if (! $refreshResult->success()) {
            return $refreshResult;
        }

        $result->wallets = $refreshResult->wallets;

        return $result;
    }

    private function readiness(): UsageBucketReadiness
    {
        return $this->bucketReadiness ?? app(UsageBucketReadiness::class);
    }

    private function metrics(): RealtimeUsageMetrics
    {
        return $this->metrics ?? app(RealtimeUsageMetrics::class);
    }

    // -- private ---------------------------------------------------------------

    /**
     * @return string|null the skip reason, or null when the buckets are ready
     */
    private function waitForBuckets(): ?string
    {
        if ($this->expectedIngestedAt === []) {
            return null;
        }

        $startedAt = now();
        $reason = $this->pollBuckets();
        $this->metrics()->bucketWait($startedAt->diffInSeconds(now()));

        return $reason;
    }

    /**
     * Port of `poll_buckets` — returns the skip reason, or null once every
     * subscription watermark has landed in the buckets.
     */
    private function pollBuckets(): ?string
    {
        $pending = $this->expectedIngestedAt;
        $staleCutoffMs = now()->subSeconds(self::STALE_WATERMARK_CUTOFF_SECONDS)->getTimestampMs();
        $deadline = now()->addSeconds(self::BUCKET_WAIT_TIMEOUT_SECONDS);

        while (true) {
            $pending = array_filter(
                $pending,
                fn (int $watermarkMs, string $subscriptionId): bool => ! $this->bucketCaughtUp($subscriptionId, $watermarkMs),
                ARRAY_FILTER_USE_BOTH,
            );

            if ($pending === []) {
                return null;
            }

            // An old watermark means the consumer is behind, not ClickHouse:
            // sleeping on it spends the batch's deadline for nothing, so it
            // gets one check and goes back to the sweep.
            $stale = array_filter($pending, fn (int $ms): bool => $ms < $staleCutoffMs);

            if ($stale !== []) {
                $this->logPending('usage buckets behind a stale watermark', $stale);

                return 'stale_watermark';
            }

            if (now()->gt($deadline)) {
                $this->logPending('usage buckets did not catch up before refresh', $pending);

                return 'bucket_wait_timeout';
            }

            usleep((int) (self::BUCKET_WAIT_INTERVAL_SECONDS * 1_000_000));
        }
    }

    /**
     * Rails queries the buckets unscoped (any row version at the watermark
     * proves the epoch landed) and uncached (the executor turns the AR
     * query cache on, and a cached miss can only time out); both concerns
     * move behind the UsageBucketReadiness seam.
     */
    private function bucketCaughtUp(string $subscriptionId, int $watermarkMs): bool
    {
        return $this->readiness()->caughtUp($this->organizationId, $subscriptionId, $watermarkMs);
    }

    /**
     * @param  array<string, int>  $pending
     */
    private function logPending(string $reason, array $pending): void
    {
        Log::warning(sprintf(
            '[wallets] %s customer_id=%s pending=%s',
            $reason,
            $this->customerId,
            json_encode(array_keys($pending)),
        ));
    }
}
