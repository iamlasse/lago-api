<?php

declare(strict_types=1);

namespace App\Support\Metrics;

/**
 * The default MetricsSink — discards. Rails exports the counters through
 * Yabeda's Prometheus exporter inside the Karafka process; until the
 * exporter is ported there is nothing to write to.
 * TODO(port): a Prometheus/exposition sink (LAGO_KARAFKA_METRICS_PORT).
 */
final class NullMetricsSink implements MetricsSink
{
    public function increment(string $metric, array $labels = [], int $by = 1): void {}

    public function measure(string $metric, array $labels = [], float $value = 0.0): void {}
}
