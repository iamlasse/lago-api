<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use App\Models\RateOverride;
use App\Services\Billing\Phase;
use App\Services\Billing\Terms;
use App\Enums\RateCardBillingTiming;
use App\Services\Billing\RateCards\Schedule;

/**
 * Port of Rails' Billing::RateCards::{CycleWalker,Schedule} specs
 * (spec/services/billing/rate_cards/{cycle_walker,schedule}_spec.rb) — the
 * pure cycle walk, no database.
 */

/** Local DateTime parser for the spec tables. */
if (! function_exists('dt')) {
    function dt(string $spec): CarbonImmutable
    {
        return CarbonImmutable::parse($spec);
    }
}

/** The rate shape the walk reads: effective_from plus the cadence columns. */
function cardRate(CarbonImmutable $effectiveFrom, int $count = 1, string $unit = 'month'): object
{
    return (object) [
        'effective_from' => $effectiveFrom,
        'billing_interval_count' => $count,
        'billing_interval_unit' => $unit,
    ];
}

/** A phase override pinning one cadence. */
function intervalOverride(int $count, string $unit): RateOverride
{
    $override = new RateOverride;
    $override->forceFill(['billing_interval_count' => $count, 'billing_interval_unit' => $unit]);

    return $override;
}

function monthlyPhases(): array
{
    return [new Phase(code: 'standard', billingIntervalCycleCount: null, rateOverride: null)];
}

/** The plan shows bounds inclusive; the exclusive end reads as the day before. */
function cycleWindows(array $cycles): array
{
    return array_map(
        fn ($cycle) => $cycle->startedAt->toDateString().' -> '.$cycle->endedAt->subDay()->toDateString(),
        $cycles,
    );
}

// -- Validation --------------------------------------------------------------

it('rejects a schedule with no rates', function (): void {
    new Schedule(
        rates: [],
        terms: Terms::from(RateCardBillingTiming::Arrears, prorated: true),
        phases: monthlyPhases(),
        startsAt: dt('2022-01-15 00:00 UTC'),
        anchorDate: dt('2022-01-01 00:00 UTC'),
        timezone: 'UTC',
    );
})->throws(InvalidArgumentException::class, 'at least one rate');

it('rejects an end before the start', function (): void {
    new Schedule(
        rates: [cardRate(dt('2000-01-01 00:00 UTC'))],
        terms: Terms::from(RateCardBillingTiming::Arrears, prorated: true),
        phases: monthlyPhases(),
        startsAt: dt('2022-01-15 00:00 UTC'),
        anchorDate: dt('2022-01-01 00:00 UTC'),
        timezone: 'UTC',
        endsAt: dt('2022-01-14 00:00 UTC'),
    );
})->throws(InvalidArgumentException::class, 'precedes starts_at');

it('rejects an empty phase list', function (): void {
    new Schedule(
        rates: [cardRate(dt('2000-01-01 00:00 UTC'))],
        terms: Terms::from(RateCardBillingTiming::Arrears, prorated: true),
        phases: [],
        startsAt: dt('2022-01-15 00:00 UTC'),
        anchorDate: dt('2022-01-01 00:00 UTC'),
        timezone: 'UTC',
    );
})->throws(InvalidArgumentException::class, 'at least one phase');

it('rejects a bounded last phase', function (): void {
    new Schedule(
        rates: [cardRate(dt('2000-01-01 00:00 UTC'))],
        terms: Terms::from(RateCardBillingTiming::Arrears, prorated: true),
        phases: [new Phase(code: 'standard', billingIntervalCycleCount: 6, rateOverride: null)],
        startsAt: dt('2022-01-15 00:00 UTC'),
        anchorDate: dt('2022-01-01 00:00 UTC'),
        timezone: 'UTC',
    );
})->throws(InvalidArgumentException::class, 'last phase must run to the end');

it('rejects an open phase that is not the last', function (): void {
    new Schedule(
        rates: [cardRate(dt('2000-01-01 00:00 UTC'))],
        terms: Terms::from(RateCardBillingTiming::Arrears, prorated: true),
        phases: [
            new Phase(code: 'a', billingIntervalCycleCount: null, rateOverride: null),
            new Phase(code: 'b', billingIntervalCycleCount: null, rateOverride: null),
        ],
        startsAt: dt('2022-01-15 00:00 UTC'),
        anchorDate: dt('2022-01-01 00:00 UTC'),
        timezone: 'UTC',
    );
})->throws(InvalidArgumentException::class, 'only the last phase');

// -- CycleWalker#start / #advance -------------------------------------------

it('opens cycle zero on the anchor month-end grid', function (): void {
    $walker = new App\Services\Billing\RateCards\CycleWalker(
        rates: [cardRate(dt('2026-01-01 00:00 UTC'))],
        phases: monthlyPhases(),
        startsAt: dt('2026-01-31 00:00 UTC'),
        anchorDate: dt('2026-01-31 00:00 UTC'),
        timezone: 'UTC',
    );

    $cycle = $walker->start();

    expect($cycle->index)->toBe(0)
        ->and($cycle->startedAt->toDateString())->toBe('2026-01-31')
        ->and($cycle->endedAt->toDateString())->toBe('2026-02-28')
        ->and($walker->currentCycle)->toBe($cycle);
});

it('starts inside a calendar interval on the first billing day, keeping the anchor boundary', function (): void {
    $walker = new App\Services\Billing\RateCards\CycleWalker(
        rates: [cardRate(dt('2026-01-01 00:00 UTC'))],
        phases: monthlyPhases(),
        startsAt: dt('2026-02-10 14:30 UTC'),
        anchorDate: dt('2026-01-31 00:00 UTC'),
        timezone: 'UTC',
    );

    $cycle = $walker->start();

    expect($cycle->startedAt->toDateString())->toBe('2026-02-10')
        ->and($cycle->endedAt->toDateString())->toBe('2026-02-28');
});

it('clips the cycle to the actual end of service', function (): void {
    $walker = new App\Services\Billing\RateCards\CycleWalker(
        rates: [cardRate(dt('2026-01-01 00:00 UTC'))],
        phases: monthlyPhases(),
        startsAt: dt('2026-01-31 00:00 UTC'),
        anchorDate: dt('2026-01-31 00:00 UTC'),
        timezone: 'UTC',
        endsAt: dt('2026-02-15 12:00 UTC'),
    );

    expect($walker->start()->endedAt->equalTo(dt('2026-02-15 12:00 UTC')))->toBeTrue();
});

it('uses the local signing day and local boundaries west of UTC', function (): void {
    // Feb 1 02:00 UTC is Jan 31 21:00 in New York — a different signing day.
    $walker = new App\Services\Billing\RateCards\CycleWalker(
        rates: [cardRate(dt('2026-01-01 00:00 UTC'))],
        phases: monthlyPhases(),
        startsAt: dt('2026-02-01 02:00 UTC'),
        anchorDate: dt('2026-01-31 00:00 UTC'),
        timezone: 'America/New_York',
    );

    $cycle = $walker->start();

    expect($cycle->startedAt->equalTo(dt('2026-01-31 00:00 America/New_York')))->toBeTrue()
        ->and($cycle->endedAt->equalTo(dt('2026-02-28 00:00 America/New_York')))->toBeTrue();
});

it('returns nil from advance before the walker has started', function (): void {
    $walker = new App\Services\Billing\RateCards\CycleWalker(
        rates: [cardRate(dt('2026-01-01 00:00 UTC'))],
        phases: monthlyPhases(),
        startsAt: dt('2026-01-31 00:00 UTC'),
        anchorDate: dt('2026-01-31 00:00 UTC'),
        timezone: 'UTC',
    );

    expect($walker->advance())->toBeNull();
});

it('continues from the previous cycle end and preserves the month-end anchor', function (): void {
    $walker = new App\Services\Billing\RateCards\CycleWalker(
        rates: [cardRate(dt('2026-01-01 00:00 UTC'))],
        phases: monthlyPhases(),
        startsAt: dt('2026-01-31 00:00 UTC'),
        anchorDate: dt('2026-01-31 00:00 UTC'),
        timezone: 'UTC',
    );

    $walker->start();
    $next = $walker->advance();

    expect($next->index)->toBe(1)
        ->and($next->startedAt->toDateString())->toBe('2026-02-28')
        ->and($next->endedAt->toDateString())->toBe('2026-03-31');
});

it('keeps the original anchor when a phase changes without changing the interval', function (): void {
    $phases = [
        new Phase(code: 'intro', billingIntervalCycleCount: 1, rateOverride: null),
        new Phase(code: 'standard', billingIntervalCycleCount: null, rateOverride: null),
    ];

    $walker = new App\Services\Billing\RateCards\CycleWalker(
        rates: [cardRate(dt('2026-01-01 00:00 UTC'))],
        phases: $phases,
        startsAt: dt('2026-01-31 00:00 UTC'),
        anchorDate: dt('2026-01-31 00:00 UTC'),
        timezone: 'UTC',
    );

    $walker->start();
    $second = $walker->advance();

    expect($second->phase->code)->toBe('standard')
        ->and($second->startedAt->toDateString())->toBe('2026-02-28')
        ->and($second->endedAt->toDateString())->toBe('2026-03-31');
});

it('counts the intro cycles and starts the monthly calendar where the intro ends', function (): void {
    // A 2-cycle weekly intro, then the card's monthly cadence.
    $phases = [
        new Phase(code: 'intro', billingIntervalCycleCount: 2, rateOverride: intervalOverride(1, 'week')),
        new Phase(code: 'standard', billingIntervalCycleCount: null, rateOverride: null),
    ];

    $walker = new App\Services\Billing\RateCards\CycleWalker(
        rates: [cardRate(dt('2026-01-01 00:00 UTC'))],
        phases: $phases,
        startsAt: dt('2026-01-31 00:00 UTC'),
        anchorDate: dt('2026-01-31 00:00 UTC'),
        timezone: 'UTC',
    );

    $walker->start();
    $cycles = [$walker->currentCycle];
    while (count($cycles) < 4 && ($next = $walker->advance()) !== null) {
        $cycles[] = $next;
    }

    expect(cycleWindows($cycles))->toBe([
        '2026-01-31 -> 2026-02-06',
        '2026-02-07 -> 2026-02-13',
        '2026-02-14 -> 2026-03-13',
        '2026-03-14 -> 2026-04-13',
    ])
        ->and(array_map(fn ($cycle) => $cycle->phase->code, $cycles))
        ->toBe(['intro', 'intro', 'standard', 'standard']);
});

it('stops exhausted at the end of service and stays exhausted', function (): void {
    $walker = new App\Services\Billing\RateCards\CycleWalker(
        rates: [cardRate(dt('2026-01-01 00:00 UTC'))],
        phases: monthlyPhases(),
        startsAt: dt('2026-01-31 00:00 UTC'),
        anchorDate: dt('2026-01-31 00:00 UTC'),
        timezone: 'UTC',
        endsAt: dt('2026-03-15 00:00 UTC'),
    );

    $walker->start();
    $second = $walker->advance();

    // Clipped to the end of service...
    expect($second->endedAt->equalTo(dt('2026-03-15 00:00 UTC')))->toBeTrue()
        // ...and no empty cycle after it.
        ->and($walker->advance())->toBeNull();
});

it('keeps the boundaries at local midnight when daylight saving time starts', function (): void {
    // America/New_York springs forward on 2026-03-08.
    $walker = new App\Services\Billing\RateCards\CycleWalker(
        rates: [cardRate(dt('2026-01-01 00:00 UTC'))],
        phases: monthlyPhases(),
        startsAt: dt('2026-02-15 00:00 America/New_York'),
        anchorDate: dt('2026-02-15 00:00 America/New_York'),
        timezone: 'America/New_York',
    );

    $cycle = $walker->start();

    expect($cycle->endedAt->equalTo(dt('2026-03-15 00:00 America/New_York')))->toBeTrue();
});

// -- CycleWalker#resume / #walk_to ------------------------------------------

it('jumps across a long history without building the intermediate cycles', function (): void {
    $walker = new App\Services\Billing\RateCards\CycleWalker(
        rates: [cardRate(dt('2026-01-01 00:00 UTC'))],
        phases: monthlyPhases(),
        startsAt: dt('2026-01-31 00:00 UTC'),
        anchorDate: dt('2026-01-31 00:00 UTC'),
        timezone: 'UTC',
    );

    $cycle = $walker->resume(dt('2027-01-31 00:00 UTC'));

    expect($cycle->index)->toBe(12)
        ->and($cycle->startedAt->toDateString())->toBe('2027-01-31');
});

it('counts the partial first cycle once and preserves the original anchor', function (): void {
    // The card starts mid-interval: the partial first cycle counts as cycle 0
    // and the boundaries stay on the anchor grid.
    $walker = new App\Services\Billing\RateCards\CycleWalker(
        rates: [cardRate(dt('2026-01-01 00:00 UTC'))],
        phases: monthlyPhases(),
        startsAt: dt('2026-02-10 00:00 UTC'),
        anchorDate: dt('2026-01-31 00:00 UTC'),
        timezone: 'UTC',
    );

    $cycle = $walker->resume(dt('2026-04-01 00:00 UTC'));

    expect($cycle->index)->toBe(2)
        ->and($cycle->startedAt->toDateString())->toBe('2026-03-31')
        ->and($cycle->endedAt->toDateString())->toBe('2026-04-30');
});

it('selects the cycle opening exactly at the requested time', function (): void {
    $walker = new App\Services\Billing\RateCards\CycleWalker(
        rates: [cardRate(dt('2026-01-01 00:00 UTC'))],
        phases: monthlyPhases(),
        startsAt: dt('2026-01-31 00:00 UTC'),
        anchorDate: dt('2026-01-31 00:00 UTC'),
        timezone: 'UTC',
    );

    $cycle = $walker->resume(dt('2026-02-28 00:00 UTC'));

    expect($cycle->index)->toBe(1)
        ->and($cycle->startedAt->toDateString())->toBe('2026-02-28');
});

it('replays the cycles that had started by the timestamp', function (): void {
    $walker = new App\Services\Billing\RateCards\CycleWalker(
        rates: [cardRate(dt('2026-01-01 00:00 UTC'))],
        phases: monthlyPhases(),
        startsAt: dt('2026-01-31 00:00 UTC'),
        anchorDate: dt('2026-01-31 00:00 UTC'),
        timezone: 'UTC',
    );

    $cycles = $walker->walkTo(dt('2026-04-15 00:00 UTC'));

    expect(cycleWindows($cycles))->toBe([
        '2026-01-31 -> 2026-02-27',
        '2026-02-28 -> 2026-03-30',
        '2026-03-31 -> 2026-04-29',
    ])
        ->and(array_map(fn ($cycle) => $cycle->index, $cycles))->toBe([0, 1, 2]);
});

it('uses the earliest rate cadence before pricing starts', function (): void {
    $walker = new App\Services\Billing\RateCards\CycleWalker(
        rates: [
            cardRate(dt('2026-06-01 00:00 UTC'), 1, 'week'),
            cardRate(dt('2026-09-01 00:00 UTC'), 1, 'month'),
        ],
        phases: monthlyPhases(),
        startsAt: dt('2026-01-01 00:00 UTC'),
        anchorDate: dt('2026-01-01 00:00 UTC'),
        timezone: 'UTC',
    );

    $cycles = $walker->walkTo(dt('2026-03-01 00:00 UTC'));

    expect(array_slice(cycleWindows($cycles), 0, 3))->toBe([
        '2026-01-01 -> 2026-01-07',
        '2026-01-08 -> 2026-01-14',
        '2026-01-15 -> 2026-01-21',
    ])
        ->and(array_map(fn ($cycle) => $cycle->index, $cycles))->toBe(range(0, 8));
});

// -- Schedule ----------------------------------------------------------------

function arrearsSchedule(): Schedule
{
    return new Schedule(
        rates: [cardRate(dt('2000-01-01 00:00 UTC'))],
        terms: Terms::from(RateCardBillingTiming::Arrears, prorated: false),
        phases: monthlyPhases(),
        startsAt: dt('2022-01-15 00:00 UTC'),
        anchorDate: dt('2022-01-01 00:00 UTC'),
        timezone: 'UTC',
    );
}

function segmentWindows(array $segments): array
{
    return array_map(
        fn ($segment) => $segment->startedAt->toDateString().' -> '.$segment->endedAt->subDay()->toDateString(),
        $segments,
    );
}

it('clamps the first cycle to the start rather than the boundary before it', function (): void {
    expect(segmentWindows(arrearsSchedule()->segmentsDueBy(dt('2022-03-01 00:00 UTC'))))->toBe([
        '2022-01-15 -> 2022-01-31',
        '2022-02-01 -> 2022-02-28',
    ]);
});

it('leaves out a cycle that has not closed by the timestamp', function (): void {
    expect(segmentWindows(arrearsSchedule()->segmentsDueBy(dt('2022-02-10 00:00 UTC'))))
        ->toBe(['2022-01-15 -> 2022-01-31']);
});

it('numbers cycles from zero counting from the card start', function (): void {
    expect(array_map(
        fn ($segment) => $segment->cycleIndex,
        arrearsSchedule()->segmentsDueBy(dt('2022-05-01 00:00 UTC')),
    ))->toBe([0, 1, 2, 3]);
});

it('returns nothing when nothing has fallen due in arrears', function (): void {
    expect(arrearsSchedule()->segmentsDueBy(dt('2022-01-15 00:00 UTC')))->toBe([]);
});

it('includes the first cycle as soon as it starts when billed in advance', function (): void {
    $schedule = new Schedule(
        rates: [cardRate(dt('2000-01-01 00:00 UTC'))],
        terms: Terms::from(RateCardBillingTiming::Advance, prorated: false),
        phases: monthlyPhases(),
        startsAt: dt('2022-01-15 00:00 UTC'),
        anchorDate: dt('2022-01-01 00:00 UTC'),
        timezone: 'UTC',
    );

    expect(array_map(
        fn ($segment) => $segment->cycleIndex,
        $schedule->segmentsDueBy(dt('2022-01-15 00:00 UTC')),
    ))->toBe([0])
        ->and(array_map(
            fn ($segment) => $segment->cycleIndex,
            $schedule->segmentsDueBy(dt('2022-02-01 00:00 UTC')),
        ))->toBe([0, 1]);
});

it('bills the cycle end in arrears and the start in advance', function (): void {
    $segments = arrearsSchedule()->segmentsDueBy(dt('2022-03-01 00:00 UTC'));

    // Arrears bills the cycle's EXCLUSIVE end.
    expect($segments[0]->billingAt->toDateString())->toBe('2022-02-01')
        ->and($segments[1]->billingAt->toDateString())->toBe('2022-03-01');
});

it('snaps the billing date forward when the next instant is requested', function (): void {
    $schedule = arrearsSchedule();

    $next = $schedule->nextBillingAt(dt('2022-01-20 00:00 UTC'));

    expect($next->toDateString())->toBe('2022-02-01');

    // An unbounded card always has a next one: the cycle containing the
    // given instant closes on the ANCHOR grid (the 1st), not the start day.
    expect($schedule->nextBillingAt(dt('2030-01-31 00:00 UTC'))->toDateString())->toBe('2030-02-01');
});

it('measures consumption against the served segment in days', function (): void {
    $schedule = arrearsSchedule();
    $segments = $schedule->segmentsDueBy(dt('2022-03-01 00:00 UTC'));

    // The full first segment: consumed completely by its exclusive end.
    expect($schedule->consumedRatio($segments[0], dt('2022-02-01 00:00 UTC')))->toEqualWithDelta(1.0, 1e-12)
        // Halfway through the 17-day first segment.
        ->and($schedule->consumedRatio($segments[0], dt('2022-01-24 00:00 UTC')))->toEqualWithDelta(9.0 / 17.0, 1e-12);
});
