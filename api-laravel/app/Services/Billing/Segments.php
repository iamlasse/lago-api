<?php

declare(strict_types=1);

namespace App\Services\Billing;

use Carbon\CarbonInterface;

/**
 * Port of Rails' Billing::Segments (app/services/billing/segments.rb) —
 * splits the half-open window at each rate change, keeping unpriced periods
 * with rate: null.
 */
final class Segments
{
    /** The rate that prices the slice, or null when none covers its start yet. */
    public readonly ?object $rate;

    public function __construct(
        public readonly CarbonInterface $startedAt,
        public readonly CarbonInterface $endedAt,
        ?object $rate,
    ) {
        $this->rate = $rate;
    }

    /**
     * Rails: `Segments.within(window, rates:)` — the window is anything
     * answering started_at/ended_at (a Cycle in production).
     *
     * @param  list<object>  $rates
     * @return list<self>
     */
    public static function within(object $window, array $rates): array
    {
        $boundaries = [$window->startedAt, ...self::rateChangesInside($window, $rates), $window->endedAt];

        $segments = [];
        $count = count($boundaries);
        for ($i = 0; $i < $count - 1; $i++) {
            $segments[] = new self(
                startedAt: $boundaries[$i],
                endedAt: $boundaries[$i + 1],
                rate: self::rateAt($rates, $boundaries[$i]),
            );
        }

        return $segments;
    }

    /**
     * Rails: `rate_changes_inside` (private).
     *
     * @param  list<object>  $rates
     * @return list<CarbonInterface>
     */
    private static function rateChangesInside(object $window, array $rates): array
    {
        $changes = [];
        foreach ($rates as $rate) {
            if ($rate->effective_from > $window->startedAt && $rate->effective_from < $window->endedAt) {
                $changes[$rate->effective_from->getTimestamp()] = $rate->effective_from;
            }
        }

        ksort($changes);

        return array_values($changes);
    }

    /**
     * Rails: `rate_at` (private) — the latest rate effective at or before the
     * timestamp.
     *
     * @param  list<object>  $rates
     */
    private static function rateAt(array $rates, CarbonInterface $timestamp): ?object
    {
        $best = null;
        foreach ($rates as $rate) {
            if ($rate->effective_from <= $timestamp
                && ($best === null || $rate->effective_from > $best->effective_from)) {
                $best = $rate;
            }
        }

        return $best;
    }
}
