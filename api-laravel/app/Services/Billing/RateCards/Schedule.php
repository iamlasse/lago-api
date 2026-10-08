<?php

declare(strict_types=1);

namespace App\Services\Billing\RateCards;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use App\Models\RateCardRate;
use InvalidArgumentException;
use App\Services\Billing\Days;
use App\Services\Billing\Cycle;
use App\Services\Billing\Phase;
use App\Services\Billing\Terms;
use App\Services\Billing\Segments;
use App\Services\Billing\BillableSegment;

/**
 * Port of Rails' Billing::RateCards::Schedule (app/services/billing/
 * rate_cards/schedule.rb) — the calendar's answer sheet: which segments a
 * card owes by a given instant, what falls due next, and how much of a
 * served segment has been consumed.
 */
final class Schedule
{
    private readonly CycleWalker $walker;

    private readonly ?CarbonImmutable $resumeAt;

    /**
     * @param  list<RateCardRate>  $rates
     * @param  list<Phase>  $phases
     */
    public function __construct(
        array $rates,
        private readonly Terms $terms,
        array $phases,
        CarbonInterface $startsAt,
        CarbonInterface $anchorDate,
        private readonly string $timezone,
        ?CarbonInterface $endsAt = null,
        ?CarbonInterface $resumeAt = null,
    ) {
        if ($resumeAt !== null
            && CarbonImmutable::createFromInterface($resumeAt)->lessThan(
                CarbonImmutable::parse($startsAt)->setTimezone($timezone)->startOfDay(),
            )) {
            throw new InvalidArgumentException(
                "resume_at {$resumeAt} precedes the card's start",
            );
        }

        $this->resumeAt = $resumeAt !== null ? CarbonImmutable::createFromInterface($resumeAt) : null;

        $this->walker = new CycleWalker(
            rates: $rates,
            phases: $phases,
            startsAt: $startsAt,
            anchorDate: $anchorDate,
            timezone: $timezone,
            endsAt: $endsAt,
        );
    }

    /**
     * Rails: `#segments_due_by` — a rate change can make a segment due before
     * its cycle ends. The walk resumes from the last stored cycle start, so a
     * settled history is replayed rather than re-walked.
     *
     * @return list<BillableSegment>
     */
    public function segmentsDueBy(CarbonInterface $timestamp, ?CarbonInterface $from = null): array
    {
        $cycles = $this->walker->walkTo($timestamp, from: $from ?? $this->resumeAt);

        $segments = $this->billableSegmentsOf($cycles);

        return array_values(array_filter(
            $segments,
            fn (BillableSegment $segment): bool => $segment->billingAt->lte(CarbonImmutable::createFromInterface($timestamp)),
        ));
    }

    /**
     * Rails: `#next_billing_at(after:)` — the next billing instant strictly
     * after the given time, or null when billing has ended.
     */
    public function nextBillingAt(CarbonInterface $after): ?CarbonImmutable
    {
        $cycle = $this->walker->resume($after);

        while ($cycle !== null) {
            $segments = $this->billableSegmentsOf([$cycle]);

            foreach ($segments as $candidate) {
                if ($candidate->billingAt->gt(CarbonImmutable::createFromInterface($after))) {
                    return CarbonImmutable::createFromInterface($candidate->billingAt);
                }
            }

            $cycle = $this->walker->advance();
        }

        return null;
    }

    /**
     * Rails: `#billing_at_covering` — bill the segment being served, or the
     * first future one if pricing has not started.
     */
    public function billingAtCovering(CarbonInterface $timestamp): ?CarbonImmutable
    {
        $segment = $this->segmentAt($timestamp);

        return $segment?->billingAt ?? $this->nextBillingAt($timestamp);
    }

    /**
     * Rails: `#consumed_ratio` — measure consumption against the original
     * billed segment, whose end is exclusive.
     */
    public function consumedRatio(BillableSegment $segment, CarbonInterface $at): float
    {
        $segmentDays = Days::between($segment->startedAt, $segment->endedAt, $this->timezone);

        if ($segmentDays === 0) {
            return 1.0;
        }

        $consumedUntil = CarbonImmutable::parse($at)
            ->setTimezone($this->timezone)
            ->max(CarbonImmutable::createFromInterface($segment->startedAt))
            ->min(CarbonImmutable::createFromInterface($segment->endedAt));

        $consumedDays = Days::between($segment->startedAt, $consumedUntil, $this->timezone);

        return $consumedDays / $segmentDays;
    }

    private function segmentAt(CarbonInterface $timestamp): ?BillableSegment
    {
        $cycle = $this->walker->resume($timestamp);

        if ($cycle === null) {
            return null;
        }

        foreach ($this->billableSegmentsOf([$cycle]) as $segment) {
            if ($segment->startedAt->lte($timestamp) && $segment->endedAt->gt($timestamp)) {
                return $segment;
            }
        }

        return null;
    }

    /**
     * Rails: `#billable_segments_of` (private) — only windows the card can
     * price become segments; unpriced stretches are dropped.
     *
     * @param  list<Cycle>  $cycles
     * @return list<BillableSegment>
     */
    private function billableSegmentsOf(array $cycles): array
    {
        $segments = [];

        foreach ($cycles as $cycle) {
            foreach (Segments::within($cycle, $this->walkerRates()) as $slice) {
                if ($slice->rate !== null) {
                    $segments[] = $this->buildBillableSegment(cycle: $cycle, slice: $slice);
                }
            }
        }

        return $segments;
    }

    private function buildBillableSegment(Cycle $cycle, Segments $slice): BillableSegment
    {
        $prorationRatio = $this->terms->prorated
            ? $cycle->calendar->prorationRatio($slice->startedAt, $slice->endedAt)
            : 1.0;

        return new BillableSegment(
            cycleIndex: $cycle->index,
            cycleStartedAt: $cycle->startedAt,
            startedAt: $slice->startedAt,
            endedAt: $slice->endedAt,
            billingAt: $this->terms->billingAtFor($slice->startedAt, $slice->endedAt),
            rate: $slice->rate,
            rateOverride: $cycle->phase->rateOverride,
            prorationRatio: $prorationRatio,
            ratePhaseCode: $cycle->phase->code,
        );
    }

    /**
     * The walker owns the sorted rate timeline; the schedule reads it back
     * for the per-cycle splitting.
     *
     * @return list<RateCardRate>
     */
    private function walkerRates(): array
    {
        return $this->walker->rates();
    }
}
