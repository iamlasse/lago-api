<?php

declare(strict_types=1);

namespace App\Services\Billing;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use App\Enums\RateCardRateBillingIntervalUnit;

/**
 * Port of Rails' Billing::Interval (app/services/billing/interval.rb) — a
 * cadence: how far one billing period runs.
 */
final class Interval
{
    public function __construct(
        public readonly int $count,
        public readonly RateCardRateBillingIntervalUnit $unit,
    ) {
        if ($count < 1) {
            throw new InvalidArgumentException(
                "interval count must be a positive integer, got {$count}",
            );
        }
    }

    /**
     * Rails: `Interval.from(rate, override:)` — the cadence a rate bills on,
     * with an override laid over it field by field (either column alone wins).
     */
    public static function from(object $rate, ?object $override = null): self
    {
        $count = $override?->billing_interval_count ?? $rate->billing_interval_count;
        $unit = $override?->billing_interval_unit ?? $rate->billing_interval_unit;

        $unit = $unit instanceof RateCardRateBillingIntervalUnit
            ? $unit
            : (RateCardRateBillingIntervalUnit::tryFrom((string) $unit)
                ?? throw new InvalidArgumentException(
                    'unknown interval unit '.var_export($unit, true),
                ));

        // The count is never coerced: "3" or 1.5 arriving here means a
        // caller misread the column, and a quietly halved cadence is the
        // worst answer this class can give.
        if (! is_int($count)) {
            throw new InvalidArgumentException(
                'interval count must be a positive integer, got '.var_export($count, true),
            );
        }

        return new self(count: $count, unit: $unit);
    }

    /** Rails: equality — a cadence change re-anchors the calendar mid-walk. */
    public function equals(self $other): bool
    {
        return $this->count === $other->count && $this->unit === $other->unit;
    }

    /**
     * Rails: `#advance(timestamp, steps)` — move an instant by whole
     * intervals. Month-end clamped (Rails `Time#advance`: Jan 31 + 1 month =
     * Feb 28, not Mar 3); steps may be negative.
     */
    public function advance(CarbonInterface $timestamp, int $steps): CarbonImmutable
    {
        $units = $this->count * $steps;
        $instant = CarbonImmutable::createFromInterface($timestamp);

        return match ($this->unit) {
            RateCardRateBillingIntervalUnit::Day => $instant->addDays($units),
            RateCardRateBillingIntervalUnit::Week => $instant->addWeeks($units),
            RateCardRateBillingIntervalUnit::Month => $units >= 0
                ? $instant->addMonthsNoOverflow($units)
                : $instant->subMonthsNoOverflow(-$units),
            RateCardRateBillingIntervalUnit::Year => $units >= 0
                ? $instant->addYearsNoOverflow($units)
                : $instant->subYearsNoOverflow(-$units),
        };
    }

    /**
     * Rails: `#steps_between(from, to)` — whole intervals between two
     * instants; may be negative. The calendar difference alone over-counts a
     * clamped anchor month (Jan 31 → Feb 15 changed month, but a month has
     * not passed), hence the correction.
     */
    public function stepsBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        $estimate = $this->calendarStepsBetween($from, $to);

        if ($this->advance($from, $estimate)->gt(CarbonImmutable::createFromInterface($to))) {
            return $estimate - 1;
        }

        return $estimate;
    }

    /**
     * Rails: `#calendar_steps_between` (private) — whole units by the
     * calendar, then how many intervals of `count` fit in them. Over by at
     * most one, which stepsBetween corrects.
     */
    private function calendarStepsBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        $from = CarbonImmutable::createFromInterface($from);
        $to = CarbonImmutable::createFromInterface($to);

        $wholeUnits = match ($this->unit) {
            RateCardRateBillingIntervalUnit::Day => (int) $from->startOfDay()->diffInDays($to->startOfDay()),
            RateCardRateBillingIntervalUnit::Week => intdiv((int) $from->startOfDay()->diffInDays($to->startOfDay()), 7),
            RateCardRateBillingIntervalUnit::Month => (($to->year - $from->year) * 12) + ($to->month - $from->month),
            RateCardRateBillingIntervalUnit::Year => $to->year - $from->year,
        };

        return intdiv($wholeUnits, $this->count);
    }
}
