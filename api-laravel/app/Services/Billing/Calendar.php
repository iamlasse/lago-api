<?php

declare(strict_types=1);

namespace App\Services\Billing;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Port of Rails' Billing::Calendar (app/services/billing/calendar.rb) — a
 * ruler: boundaries every `interval` from the start of the anchor day, in
 * the customer's timezone. Anchored Feb 1 in "America/New_York", boundary 0
 * is Feb 1 05:00 UTC.
 */
final class Calendar
{
    /** @var array<int, CarbonImmutable> memoized boundaries (Rails @boundaries) */
    private array $boundaries = [];

    private readonly CarbonImmutable $anchor;

    public function __construct(
        public readonly CarbonInterface $anchorDate,
        public readonly Interval $interval,
        private readonly string $timezone,
    ) {
        // Rails: anchor_date.in_time_zone(timezone).beginning_of_day — the
        // anchor is a DATE, so its calendar day (read in the instant's own
        // zone) becomes local midnight in the customer zone. Shifting a
        // UTC-midnight instant with setTimezone alone would land on the
        // previous day.
        $this->anchor = CarbonImmutable::parse($this->anchorDate->format('Y-m-d'), $timezone)
            ->startOfDay();
    }

    /**
     * Rails: `#interval_containing` — the half-open interval the timestamp
     * falls in, as [boundary, next boundary).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function intervalContaining(CarbonInterface $timestamp): array
    {
        $index = $this->indexOfInterval($timestamp);

        return [$this->intervalWithIndexStartsAt($index), $this->intervalWithIndexStartsAt($index + 1)];
    }

    /**
     * Rails: `#intervals_between(from, to)` — how many intervals separate two
     * instants on this ruler. A monthly ruler anchored Jan 31 clamps to
     * Feb 28, so intervals_between(Feb 28, Mar 31) is 1 but
     * intervals_between(Feb 28, Mar 28) is 0.
     */
    public function intervalsBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        return $this->indexOfInterval($to) - $this->indexOfInterval($from);
    }

    /**
     * Rails: `#boundary_after(timestamp, steps)` — the boundary `steps`
     * intervals after the one containing the instant.
     */
    public function boundaryAfter(CarbonInterface $timestamp, int $steps): CarbonImmutable
    {
        return $this->intervalWithIndexStartsAt($this->indexOfInterval($timestamp) + $steps);
    }

    /**
     * Rails: `#boundary_at_or_after` — the first boundary at or after the
     * instant: when a change landing mid-interval takes hold.
     */
    public function boundaryAtOrAfter(CarbonInterface $timestamp): CarbonImmutable
    {
        [$windowStart, $windowEnd] = $this->intervalContaining($timestamp);

        return $windowStart->equalTo(CarbonImmutable::createFromInterface($timestamp))
            ? $windowStart
            : $windowEnd;
    }

    /**
     * Rails: `#proration_ratio(from, to)` — the share of its containing
     * interval that the half-open window covers. The denominator is the
     * CONTAINING interval, never a fixed 30 days.
     */
    public function prorationRatio(CarbonInterface $from, CarbonInterface $to): float
    {
        $from = CarbonImmutable::createFromInterface($from);
        $to = CarbonImmutable::createFromInterface($to);

        if ($to->lessThan($from)) {
            throw new InvalidArgumentException(
                "window end {$to} precedes its start {$from}",
            );
        }

        [$windowStart, $windowEnd] = $this->intervalContaining($from);

        if ($to->greaterThan($windowEnd)) {
            throw new InvalidArgumentException(
                "window [{$from}, {$to}) crosses the boundary at {$windowEnd}",
            );
        }

        $windowDays = Days::between($windowStart, $windowEnd, $this->timezone);

        return $windowDays === 0 ? 0.0 : Days::between($from, $to, $this->timezone) / $windowDays;
    }

    /**
     * Rails: `#index_of_interval` (private) — which interval the timestamp
     * falls in: a position on this ruler, not a cycle number. The anchor is a
     * reference day, not a start date, so instants before it have negative
     * indices.
     */
    private function indexOfInterval(CarbonInterface $timestamp): int
    {
        return $this->interval->stepsBetween($this->anchor, $timestamp);
    }

    private function intervalWithIndexStartsAt(int $index): CarbonImmutable
    {
        return $this->boundaries[$index] ??= $this->interval->advance($this->anchor, $index);
    }
}
