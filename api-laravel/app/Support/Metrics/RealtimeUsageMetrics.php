<?php

declare(strict_types=1);

namespace App\Support\Metrics;

/**
 * Port of Rails' `Yabeda.realtime_usage` metric group (config/initializers
 * registering the Yabeda counters) — the series the wallet refresh
 * consumer and the realtime refresh service record.
 *
 * Resolved through the container so tests bind an instrumented sink:
 * `$this->app->instance(MetricsSink::class, $spy)`.
 */
final class RealtimeUsageMetrics
{
    public function __construct(private readonly MetricsSink $sink = new NullMetricsSink) {}

    /** `wallet_refresh_messages_total` — trigger vs tombstone. */
    public function message(string $kind, int $by = 1): void
    {
        $this->sink->increment('wallet_refresh_messages_total', ['kind' => $kind], $by);
    }

    /** `wallet_refresh_outcomes_total` — refreshed/skipped/failed + reason. */
    public function outcome(string $outcome, string $reason, int $by = 1): void
    {
        $this->sink->increment('wallet_refresh_outcomes_total', ['outcome' => $outcome, 'reason' => $reason], $by);
    }

    /** `wallet_refresh_latency` — trigger watermark to refresh, seconds. */
    public function latency(float $seconds): void
    {
        $this->sink->measure('wallet_refresh_latency', [], $seconds);
    }

    /** `wallet_refresh_duration` — the Customers::RefreshWalletsService call. */
    public function refreshDuration(float $seconds): void
    {
        $this->sink->measure('wallet_refresh_duration', [], $seconds);
    }

    /** `wallet_refresh_bucket_wait` — the poll_buckets wait, seconds. */
    public function bucketWait(float $seconds): void
    {
        $this->sink->measure('wallet_refresh_bucket_wait', [], $seconds);
    }
}
