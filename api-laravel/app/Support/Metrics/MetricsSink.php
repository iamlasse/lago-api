<?php

declare(strict_types=1);

namespace App\Support\Metrics;

/**
 * Port of the Yabeda sink surface the realtime-usage consumers use
 * (`Yabeda.realtime_usage.*`): counters increment by N with labels,
 * gauges/timers observe one value.
 *
 * TODO(port): the Prometheus exporter (Rails runs it inside the Karafka
 * process on LAGO_KARAFKA_METRICS_PORT); the default sink discards.
 */
interface MetricsSink
{
    /** @param  array<string, string|int|null>  $labels */
    public function increment(string $metric, array $labels = [], int $by = 1): void;

    /** @param  array<string, string|int|null>  $labels */
    public function measure(string $metric, array $labels = [], float $value = 0.0): void;
}
