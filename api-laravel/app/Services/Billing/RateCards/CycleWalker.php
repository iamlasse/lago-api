<?php

declare(strict_types=1);

namespace App\Services\Billing\RateCards;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use App\Services\Billing\Cycle;
use App\Services\Billing\Phase;
use App\Services\Billing\Calendar;
use App\Services\Billing\Interval;

/**
 * Port of Rails' Billing::RateCards::CycleWalker (app/services/billing/
 * rate_cards/cycle_walker.rb) — walks a card's cycles: the current one, its
 * successor, and the ones between an arbitrary instant and now, cutting each
 * at the phase and rate boundaries it crosses.
 */
final class CycleWalker
{
    public private(set) ?Cycle $currentCycle = null;

    /**
     * The rate timeline, sorted by effective_from. Duck-typed like Rails'
     * Struct specs read it: `effective_from` plus the two cadence columns.
     *
     * @var list<object>
     */
    private readonly array $rates;

    /** @var list<Phase> */
    private readonly array $phases;

    private readonly CarbonImmutable $startsAt;

    /** @var array<string, Calendar> memoized per [anchor, interval] (Rails @calendars) */
    private array $calendars = [];

    /**
     * @param  list<object>  $rates
     * @param  list<Phase>  $phases
     */
    public function __construct(
        array $rates,
        array $phases,
        CarbonInterface $startsAt,
        private readonly CarbonInterface $anchorDate,
        private readonly string $timezone,
        private readonly ?CarbonInterface $endsAt = null,
    ) {
        $this->rates = self::sortedRates($rates);
        $this->phases = $phases;
        $this->startsAt = CarbonImmutable::parse($startsAt)->setTimezone($timezone)->startOfDay();

        $this->validate($rates, $phases);
    }

    public function start(): ?Cycle
    {
        return $this->currentCycle = $this->buildCycle(index: 0, startedAt: $this->startsAt);
    }

    /** The rate timeline, sorted by effective_from (Rails rates.sort_by). */
    public function rates(): array
    {
        return $this->rates;
    }

    public function advance(): ?Cycle
    {
        if ($this->currentCycle === null) {
            return null;
        }

        return $this->currentCycle = $this->buildCycle(
            index: $this->currentCycle->index + 1,
            startedAt: $this->currentCycle->endedAt,
            previousCalendar: $this->currentCycle->calendar,
        );
    }

    /** Rails: `#resume` — the cycle containing the timestamp; nil starts over. */
    public function resume(?CarbonInterface $timestamp): ?Cycle
    {
        $this->start();

        if ($timestamp === null) {
            return $this->currentCycle;
        }

        if ($this->endsAt !== null && CarbonImmutable::createFromInterface($timestamp)->gte($this->endsAt)) {
            $this->currentCycle = null;
        }

        while ($this->currentCycle !== null
            && $this->currentCycle->endedAt->lte(CarbonImmutable::createFromInterface($timestamp))) {
            $steps = $this->stepsToAdvance($timestamp);
            $calendar = $this->currentCycle->calendar;

            $this->currentCycle = $this->buildCycle(
                index: $this->currentCycle->index + $steps,
                startedAt: $calendar->boundaryAfter($this->currentCycle->startedAt, $steps),
                previousCalendar: $calendar,
            );
        }

        return $this->currentCycle;
    }

    /**
     * Rails: `#walk_to(timestamp, from:)` — every cycle that had started by
     * the timestamp, from the given resume point onward.
     *
     * @return list<Cycle>
     */
    public function walkTo(CarbonInterface $timestamp, ?CarbonInterface $from = null): array
    {
        $cycles = [];
        $this->resume($from);

        while ($this->currentCycle !== null
            && $this->currentCycle->startedAt->lte(CarbonImmutable::createFromInterface($timestamp))) {
            $cycles[] = $this->currentCycle;

            if ($this->currentCycle->endedAt->gt(CarbonImmutable::createFromInterface($timestamp))) {
                break;
            }

            $this->advance();
        }

        return $cycles;
    }

    /**
     * @param  list<object>  $rates
     * @return list<object>
     */
    private static function sortedRates(array $rates): array
    {
        usort($rates, fn (object $a, object $b): int => $a->effective_from <=> $b->effective_from);

        return $rates;
    }

    // -- Internals -------------------------------------------------------------

    /**
     * Rails: `#validate!` — the invariants the walk relies on.
     *
     * @param  list<object>  $rates
     * @param  list<Phase>  $phases
     */
    private function validate(array $rates, array $phases): void
    {
        if ($rates === []) {
            throw new InvalidArgumentException(
                'at least one rate is required to determine the billing interval',
            );
        }

        if ($this->endsAt !== null && CarbonImmutable::createFromInterface($this->endsAt)->lessThan($this->startsAt)) {
            throw new InvalidArgumentException(
                "ends_at {$this->endsAt} precedes starts_at {$this->startsAt}",
            );
        }

        if ($phases === []) {
            throw new InvalidArgumentException('at least one phase is required');
        }

        $unbounded = array_filter($phases, fn (Phase $phase): bool => $phase->unbounded());
        if ($unbounded === []) {
            throw new InvalidArgumentException('the last phase must run to the end of the card');
        }

        // Rails: phases[0...-1].any?(&:unbounded?)
        foreach (array_slice($phases, 0, -1) as $phase) {
            if ($phase->unbounded()) {
                throw new InvalidArgumentException('only the last phase may run to the end of the card');
            }
        }

        foreach ($this->rates as $rate) {
            Interval::from($rate);
        }

        foreach ($phases as $phase) {
            if ($phase->rateOverride !== null) {
                // The base rate supplies any interval fields the override
                // leaves unchanged.
                Interval::from($this->rates[0], override: $phase->rateOverride);
            }
        }
    }

    /**
     * Example with current_cycle.index = 3, using the current calendar:
     * steps_to_timestamp = 6 (the time falls in cycle 9), phase_start_index =
     * 4 (the next phase starts at cycle 4) → steps_to_phase_change = 1, and
     * steps_to_rate_change = 3 (the first boundary at/after the next rate is
     * cycle 6). [6, 1, 3].min = 1: advance to the phase change, apply the new
     * phase, then recalculate with the resulting calendar. An unbounded phase
     * or no later rate contributes nil, which compact removes.
     */
    private function stepsToAdvance(CarbonInterface $timestamp): int
    {
        $calendar = $this->currentCycle->calendar;
        $startedAt = $this->currentCycle->startedAt;
        $limits = [$calendar->intervalsBetween($startedAt, $timestamp)];

        $phaseStartIndex = $this->nextPhaseStartIndex();
        if ($phaseStartIndex !== null) {
            $limits[] = $phaseStartIndex - $this->currentCycle->index;
        }

        $nextRate = null;
        foreach ($this->rates as $rate) {
            if ($rate->effective_from->gt($startedAt)) {
                $nextRate = $rate;
                break;
            }
        }

        if ($nextRate !== null) {
            $boundary = $calendar->boundaryAtOrAfter($nextRate->effective_from);
            $limits[] = $calendar->intervalsBetween($startedAt, $boundary);
        }

        return min($limits);
    }

    private function nextPhaseStartIndex(): ?int
    {
        $phaseBoundaryIndex = 0;

        foreach ($this->phases as $phase) {
            if ($phase->unbounded()) {
                return null;
            }

            $phaseBoundaryIndex += $phase->billingIntervalCycleCount;
            if ($phaseBoundaryIndex > $this->currentCycle->index) {
                return $phaseBoundaryIndex;
            }
        }

        throw new InvalidArgumentException('phases must end with an unbounded phase');
    }

    private function buildCycle(int $index, CarbonInterface $startedAt, ?Calendar $previousCalendar = null): ?Cycle
    {
        $startedAt = CarbonImmutable::createFromInterface($startedAt);

        if ($this->endsAt !== null && $startedAt->gte($this->endsAt)) {
            return null;
        }

        $phase = $this->phaseByIndex($index);
        $rate = $this->rateForCycle($startedAt);
        $interval = Interval::from($rate, override: $phase->rateOverride);

        if ($previousCalendar !== null && $interval->equals($previousCalendar->interval)) {
            $calendar = $previousCalendar;
        } else {
            // Keep calculated boundaries when another resume or walk uses
            // this calendar.
            $anchor = $previousCalendar !== null
                ? $startedAt->setTimezone($this->timezone)->startOfDay()
                : $this->anchorDate;
            $key = $anchor->toDateString().'|'.$interval->count.'|'.$interval->unit->value;
            $calendar = $this->calendars[$key] ??= new Calendar(
                anchorDate: $anchor,
                interval: $interval,
                timezone: $this->timezone,
            );
        }

        [$windowStart, $windowEnd] = $calendar->intervalContaining($startedAt);

        $endedAt = $this->endsAt !== null
            ? $windowEnd->min(CarbonImmutable::createFromInterface($this->endsAt))
            : $windowEnd;

        return new Cycle(
            index: $index,
            startedAt: $startedAt,
            endedAt: $endedAt,
            phase: $phase,
            calendar: $calendar,
        );
    }

    private function phaseByIndex(int $index): Phase
    {
        foreach ($this->phases as $phase) {
            if ($phase->unbounded() || $index < $phase->billingIntervalCycleCount) {
                return $phase;
            }

            $index -= $phase->billingIntervalCycleCount;
        }

        throw new InvalidArgumentException('phases must end with an unbounded phase');
    }

    /**
     * Rails: `#rate_for_cycle` — before the first rate takes effect, use its
     * interval to build the cycle. This does not price the period before its
     * effective date.
     */
    private function rateForCycle(CarbonInterface $timestamp): object
    {
        for ($i = count($this->rates) - 1; $i >= 0; $i--) {
            if ($this->rates[$i]->effective_from->lte($timestamp)) {
                return $this->rates[$i];
            }
        }

        return $this->rates[0];
    }
}
