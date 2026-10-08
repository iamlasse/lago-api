<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use App\Models\RateCardRate;
use App\Services\Billing\Days;
use App\Services\Billing\Phase;
use App\Services\Billing\Terms;
use App\Services\Billing\Calendar;
use App\Services\Billing\Interval;
use App\Services\Billing\Segments;
use App\Enums\RateCardBillingTiming;
use App\Enums\RateCardRateBillingIntervalUnit;

/**
 * Port of the Rails billing-calendar machinery specs
 * (spec/services/billing/{days,interval,calendar,segments,terms,phase}_spec.rb)
 * — the pure date engine, no database.
 */
function billingRateStub(string $code, CarbonImmutable $effectiveFrom): RateCardRate
{
    $rate = new RateCardRate;
    $rate->forceFill(['code' => $code, 'effective_from' => $effectiveFrom]);
    // The splitter only reads effective_from, which forceFill leaves raw.

    return $rate;
}

function billingCycleWindow(CarbonImmutable $from, CarbonImmutable $to): object
{
    // The splitter only reads startedAt/endedAt — the shape of a Cycle.
    return new class($from, $to)
    {
        public function __construct(
            public readonly CarbonImmutable $startedAt,
            public readonly CarbonImmutable $endedAt,
        ) {}
    };
}

// -- Billing::Days -----------------------------------------------------------

/** Local DateTime parser for the spec tables. */
if (! function_exists('dt')) {
    function dt(string $spec): CarbonImmutable
    {
        return CarbonImmutable::parse($spec);
    }
}

it('counts the days a whole interval opens', function (): void {
    expect(Days::between(dt('2026-06-01 00:00 UTC'), dt('2026-07-01 00:00 UTC'), 'UTC'))->toBe(30);
});

it('counts nothing for a window that opens and closes inside one day', function (): void {
    expect(Days::between(dt('2026-06-01 09:00 UTC'), dt('2026-06-01 17:00 UTC'), 'UTC'))->toBe(0);
});

it('gives a day that has already opened to the window before it', function (): void {
    expect(Days::between(dt('2026-06-16 09:30 UTC'), dt('2026-07-01 00:00 UTC'), 'UTC'))->toBe(14);
});

it('keeps the day when the window opens exactly on its midnight', function (): void {
    expect(Days::between(dt('2026-06-16 00:00 UTC'), dt('2026-07-01 00:00 UTC'), 'UTC'))->toBe(15);
});

it('shares a cut interval by local days, not by UTC days', function (): void {
    // Jun 1 -> Jul 1 in New York; the cut falls at 08:00 local on the 15th.
    $before = Days::between(dt('2026-06-01 04:00 UTC'), dt('2026-06-15 12:00 UTC'), 'America/New_York');
    $after = Days::between(dt('2026-06-15 12:00 UTC'), dt('2026-07-01 04:00 UTC'), 'America/New_York');

    expect([$before, $after])->toBe([15, 15]);
});

it('splits an interval into shares that sum to it', function (): void {
    $window = [dt('2026-03-01 00:00 UTC'), dt('2026-04-01 00:00 UTC')];
    $whole = Days::between($window[0], $window[1], 'UTC');

    foreach ([0, 9, 14, 23] as $hour) {
        $cut = dt("2026-03-15 {$hour}:00 UTC");
        $before = Days::between($window[0], $cut, 'UTC');
        $after = Days::between($cut, $window[1], 'UTC');

        expect($before + $after)->toBe($whole);
    }
});

it('counts a DST day as one day like any other', function (): void {
    // New York loses an hour on 2026-03-08 and gains one on 2026-11-01.
    $march = Days::between(dt('2026-03-01 05:00 UTC'), dt('2026-04-01 04:00 UTC'), 'America/New_York');
    $november = Days::between(dt('2026-11-01 04:00 UTC'), dt('2026-12-01 05:00 UTC'), 'America/New_York');

    expect([$march, $november])->toBe([31, 30]);
});

// -- Billing::Interval -------------------------------------------------------

it('rejects an unknown interval unit string from a plain rate shape', function (): void {
    Interval::from((object) ['billing_interval_count' => 1, 'billing_interval_unit' => 'fortnight']);
})->throws(InvalidArgumentException::class, 'unknown interval unit');

it('rejects a zero interval count', function (): void {
    new Interval(count: 0, unit: RateCardRateBillingIntervalUnit::Month);
})->throws(InvalidArgumentException::class);

it('rejects a string count instead of coercing it', function (): void {
    Interval::from((object) ['billing_interval_count' => '3', 'billing_interval_unit' => 'month']);
})->throws(InvalidArgumentException::class, 'must be a positive integer');

it('reads the override field by field, the rate supplying what is unset', function (): void {
    $rate = new RateCardRate;
    $rate->forceFill(['billing_interval_count' => 3, 'billing_interval_unit' => 'month']);

    $overrideUnitOnly = new App\Models\RateOverride;
    $overrideUnitOnly->forceFill(['billing_interval_count' => null, 'billing_interval_unit' => 'week']);
    expect(Interval::from($rate, $overrideUnitOnly)->count)->toBe(3)
        ->and(Interval::from($rate, $overrideUnitOnly)->unit)->toBe(RateCardRateBillingIntervalUnit::Week);

    $overrideCountOnly = new App\Models\RateOverride;
    $overrideCountOnly->forceFill(['billing_interval_count' => 2, 'billing_interval_unit' => null]);
    expect(Interval::from($rate, $overrideCountOnly)->count)->toBe(2)
        ->and(Interval::from($rate, $overrideCountOnly)->unit)->toBe(RateCardRateBillingIntervalUnit::Month);

    $emptyOverride = new App\Models\RateOverride;
    $emptyOverride->forceFill(['billing_interval_count' => null, 'billing_interval_unit' => null]);
    expect(Interval::from($rate, $emptyOverride)->count)->toBe(3)
        ->and(Interval::from($rate, $emptyOverride)->unit)->toBe(RateCardRateBillingIntervalUnit::Month);
});

it('advances month-ends clamped, not rolled over', function (): void {
    $monthly = new Interval(count: 1, unit: RateCardRateBillingIntervalUnit::Month);
    $origin = dt('2022-01-31 00:00 UTC');

    expect((string) $monthly->advance($origin, 1)->utc())->toBe('2022-02-28 00:00:00')
        ->and((string) $monthly->advance($origin, 2)->utc())->toBe('2022-03-31 00:00:00')
        ->and((string) $monthly->advance($origin, -1)->utc())->toBe('2021-12-31 00:00:00')
        ->and((string) $monthly->advance($origin, 0)->utc())->toBe('2022-01-31 00:00:00');

    $quarterly = new Interval(count: 3, unit: RateCardRateBillingIntervalUnit::Month);
    expect((string) $quarterly->advance($origin, 1)->utc())->toBe('2022-04-30 00:00:00')
        ->and((string) $quarterly->advance(dt('2022-01-01 00:00 UTC'), 2)->utc())->toBe('2022-07-01 00:00:00');

    $leapYear = new Interval(count: 1, unit: RateCardRateBillingIntervalUnit::Year);
    expect((string) $leapYear->advance(dt('2024-02-29 00:00 UTC'), 1)->utc())->toBe('2025-02-28 00:00:00');
});

it('steps months by the local date rather than the UTC one', function (): void {
    // Mar 1 00:00 in Paris is Feb 28 23:00 UTC — a different month.
    $localMidnight = dt('2026-03-01 00:00 Europe/Paris');
    $monthly = new Interval(count: 1, unit: RateCardRateBillingIntervalUnit::Month);

    expect($monthly->advance($localMidnight, 1)->equalTo(dt('2026-04-01 00:00 Europe/Paris')))->toBeTrue()
        ->and($monthly->advance($localMidnight->utc(), 1)->equalTo(dt('2026-03-28 23:00 UTC')))->toBeTrue();
});

it('does not drift when month-ends are re-derived from the same origin', function (): void {
    $monthly = new Interval(count: 1, unit: RateCardRateBillingIntervalUnit::Month);
    $origin = dt('2022-01-31 00:00 UTC');

    $steps = array_map(
        fn (int $step): string => (string) $monthly->advance($origin, $step)->utc(),
        range(0, 3),
    );

    expect($steps)->toBe([
        '2022-01-31 00:00:00',
        '2022-02-28 00:00:00',
        '2022-03-31 00:00:00',
        '2022-04-30 00:00:00',
    ]);
});

it('fits whole intervals between two instants', function (): void {
    $cases = [
        ['month', 1, '2022-01-31 00:00 UTC', '2022-01-31 00:00 UTC', 0],
        // Feb 15 has not reached the Feb 28 step, so no whole interval has
        // closed. The calendar grid would say 1 here; the correction brings
        // it back to 0.
        ['month', 1, '2022-01-31 00:00 UTC', '2022-02-15 00:00 UTC', 0],
        ['month', 1, '2022-01-31 00:00 UTC', '2022-02-28 00:00 UTC', 1],
        ['month', 1, '2022-01-31 00:00 UTC', '2021-12-15 00:00 UTC', -2],
        ['month', 3, '2022-01-01 00:00 UTC', '2022-08-01 00:00 UTC', 2],
        ['month', 3, '2022-01-31 00:00 UTC', '2022-04-15 00:00 UTC', 0],
        ['month', 3, '2022-01-01 00:00 UTC', '2021-11-01 00:00 UTC', -1],
        ['day', 1, '2022-01-01 00:00 UTC', '2022-01-10 00:00 UTC', 9],
        ['day', 3, '2022-01-11 00:00 UTC', '2022-01-01 00:00 UTC', -4],
        ['week', 1, '2022-01-01 00:00 UTC', '2022-01-15 00:00 UTC', 2],
        ['week', 1, '2022-01-15 00:00 UTC', '2022-01-01 00:00 UTC', -2],
        ['year', 1, '2022-06-01 00:00 UTC', '2025-01-01 00:00 UTC', 2],
    ];

    foreach ($cases as [$unit, $count, $from, $to, $expected]) {
        $interval = new Interval(count: $count, unit: RateCardRateBillingIntervalUnit::from($unit));

        expect($interval->stepsBetween(dt($from), dt($to)))->toBe($expected);
    }
});

it('always lands on the last step at or before the target', function (): void {
    // The defining property: `from` advanced by the answer never passes `to`,
    // and one more step always does. Checked across a matrix, because this is
    // what pins the calendar estimate to overshooting by at most one.
    $froms = [
        dt('2022-01-31 00:00 UTC'),
        dt('2024-02-29 00:00 UTC'),
        dt('2022-07-15 00:00 UTC'),
        dt('2021-12-01 00:00 UTC'),
        dt('2022-06-30 00:00 UTC'),
        dt('2026-03-29 00:00 Europe/Paris'),
        dt('2026-10-25 00:00 Europe/Paris'),
    ];
    $offsets = [-400, -95, -31, -30, -29, -1, 0, 1, 27, 28, 29, 30, 31, 32, 59, 90, 365, 366, 730];

    $violations = [];

    foreach (RateCardRateBillingIntervalUnit::cases() as $unit) {
        foreach ([1, 3] as $count) {
            $interval = new Interval(count: $count, unit: $unit);

            foreach ($froms as $from) {
                foreach ($offsets as $offset) {
                    $to = $from->copy()->addDays($offset);
                    $steps = $interval->stepsBetween($from, $to);

                    if ($interval->advance($from, $steps)->lte($to)
                        && $interval->advance($from, $steps + 1)->gt($to)) {
                        continue;
                    }

                    $violations[] = "{$count} {$unit->value} from {$from} to {$to} gave {$steps}";
                }
            }
        }
    }

    expect($violations)->toBe([]);
});

// -- Billing::Terms ----------------------------------------------------------

it('bills the start in advance and the end in arrears', function (): void {
    $start = dt('2026-02-01 00:00 UTC');
    $end = dt('2026-03-01 00:00 UTC');

    $advance = Terms::from(RateCardBillingTiming::Advance, prorated: false);
    $arrears = Terms::from(RateCardBillingTiming::Arrears, prorated: false);

    expect($advance->billingAtFor($start, $end)->equalTo($start))->toBeTrue()
        ->and($arrears->billingAtFor($start, $end)->equalTo($end))->toBeTrue();
});

it('rejects an unknown billing timing', function (): void {
    Terms::from('fortnightly', prorated: false);
})->throws(InvalidArgumentException::class);

// -- Billing::Phase ----------------------------------------------------------

it('carries an unbounded default phase', function (): void {
    $phase = Phase::default();

    expect($phase->unbounded())->toBeTrue()
        ->and($phase->code)->toBeNull()
        ->and($phase->rateOverride)->toBeNull();
});

it('rejects a phase of zero or negative cycles', function (): void {
    new Phase(code: 'intro', billingIntervalCycleCount: 0, rateOverride: null);
})->throws(InvalidArgumentException::class);

it('rejects a second unbounded phase', function (): void {
    // Walked by CycleWalker's validate step; Phase itself accepts it.
    $phases = [
        new Phase(code: null, billingIntervalCycleCount: null, rateOverride: null),
        new Phase(code: null, billingIntervalCycleCount: 3, rateOverride: null),
    ];

    // The unbounded phase in the middle is rejected when the walker runs.
    expect($phases[0]->unbounded())->toBeTrue()->and($phases[1]->unbounded())->toBeFalse();
});

// -- Billing::Segments -------------------------------------------------------

it('splits the window at each rate change inside it', function (): void {
    $window = billingCycleWindow(dt('2026-09-10 00:00 UTC'), dt('2026-10-10 00:00 UTC'));
    $windows = fn (array $segments) => array_map(
        fn (Segments $segment) => [
            $segment->startedAt->toDateString(),
            $segment->endedAt->toDateString(),
            $segment->rate?->code,
        ],
        $segments,
    );

    // No change inside: whole window at v1.
    expect($windows(Segments::within($window, [billingRateStub('v1', dt('2026-01-01 00:00 UTC'))])))
        ->toBe([['2026-09-10', '2026-10-10', 'v1']]);

    // One change inside: two priced windows.
    expect($windows(Segments::within($window, [
        billingRateStub('v1', dt('2026-01-01 00:00 UTC')),
        billingRateStub('v2', dt('2026-09-25 00:00 UTC')),
    ])))->toBe([
        ['2026-09-10', '2026-09-25', 'v1'],
        ['2026-09-25', '2026-10-10', 'v2'],
    ]);

    // One cut per change.
    expect($windows(Segments::within($window, [
        billingRateStub('v1', dt('2026-01-01 00:00 UTC')),
        billingRateStub('v2', dt('2026-09-20 00:00 UTC')),
        billingRateStub('v3', dt('2026-09-30 00:00 UTC')),
    ])))->toBe([
        ['2026-09-10', '2026-09-20', 'v1'],
        ['2026-09-20', '2026-09-30', 'v2'],
        ['2026-09-30', '2026-10-10', 'v3'],
    ]);

    // A change landing ON the window's start or end cuts nothing.
    expect($windows(Segments::within($window, [
        billingRateStub('v1', dt('2026-01-01 00:00 UTC')),
        billingRateStub('v2', dt('2026-09-10 00:00 UTC')),
    ])))->toBe([['2026-09-10', '2026-10-10', 'v2']]);

    expect($windows(Segments::within($window, [
        billingRateStub('v1', dt('2026-01-01 00:00 UTC')),
        billingRateStub('v2', dt('2026-10-10 00:00 UTC')),
    ])))->toBe([['2026-09-10', '2026-10-10', 'v1']]);

    // Unpriced stretches are kept with rate: null.
    expect($windows(Segments::within($window, [billingRateStub('v1', dt('2027-01-01 00:00 UTC'))])))
        ->toBe([['2026-09-10', '2026-10-10', null]]);

    expect($windows(Segments::within($window, [billingRateStub('v1', dt('2026-09-25 00:00 UTC'))])))
        ->toBe([
            ['2026-09-10', '2026-09-25', null],
            ['2026-09-25', '2026-10-10', 'v1'],
        ]);

    // A rate starting at the window's exclusive end never prices it.
    expect($windows(Segments::within($window, [billingRateStub('v1', dt('2026-10-10 00:00 UTC'))])))
        ->toBe([['2026-09-10', '2026-10-10', null]]);

    // The rates need not be ordered.
    $unordered = Segments::within($window, [
        billingRateStub('v2', dt('2026-09-25 00:00 UTC')),
        billingRateStub('v1', dt('2026-01-01 00:00 UTC')),
    ]);
    expect(array_map(fn (Segments $s) => $s->rate?->code, $unordered))->toBe(['v1', 'v2']);

    // No rates: whole window unpriced.
    expect($windows(Segments::within($window, [])))->toBe([['2026-09-10', '2026-10-10', null]]);

    // Coverage: no gaps, no overlaps, including the unpriced period.
    $segments = Segments::within($window, [
        billingRateStub('v1', dt('2026-09-15 00:00 UTC')),
        billingRateStub('v2', dt('2026-09-20 00:00 UTC')),
        billingRateStub('v3', dt('2026-09-30 00:00 UTC')),
    ]);

    expect($segments[0]->startedAt->equalTo($window->startedAt))->toBeTrue()
        ->and($segments[count($segments) - 1]->endedAt->equalTo($window->endedAt))->toBeTrue();

    foreach ($segments as $index => $segment) {
        if ($index === 0) {
            continue;
        }

        expect($segment->startedAt->equalTo($segments[$index - 1]->endedAt))->toBeTrue();
    }
});

// -- Billing::Calendar -------------------------------------------------------

it('builds a ruler anchored on the anchor day in the customer timezone', function (): void {
    // Anchored Feb 1 in New York, boundary 0 is Feb 1 05:00 UTC.
    $calendar = new Calendar(
        anchorDate: dt('2026-02-01 00:00 UTC'),
        interval: new Interval(count: 1, unit: RateCardRateBillingIntervalUnit::Month),
        timezone: 'America/New_York',
    );

    [$start, $end] = $calendar->intervalContaining(dt('2026-02-10 12:00 UTC'));

    expect($start->equalTo(dt('2026-02-01 05:00 UTC')))->toBeTrue()
        ->and($end->equalTo(dt('2026-03-01 05:00 UTC')))->toBeTrue();
});

it('counts intervals between instants on the ruler, clamped anchors included', function (): void {
    $calendar = new Calendar(
        anchorDate: dt('2022-01-31 00:00 UTC'),
        interval: new Interval(count: 1, unit: RateCardRateBillingIntervalUnit::Month),
        timezone: 'UTC',
    );

    // A monthly ruler anchored Jan 31, whose boundaries clamp to Feb 28.
    expect($calendar->intervalsBetween(dt('2022-02-28 00:00 UTC'), dt('2022-03-31 00:00 UTC')))->toBe(1)
        ->and($calendar->intervalsBetween(dt('2022-02-28 00:00 UTC'), dt('2022-03-28 00:00 UTC')))->toBe(0);
});

it('finds the boundary a number of steps after the containing one', function (): void {
    $calendar = new Calendar(
        anchorDate: dt('2022-01-31 00:00 UTC'),
        interval: new Interval(count: 1, unit: RateCardRateBillingIntervalUnit::Month),
        timezone: 'UTC',
    );

    // boundary_after(Feb 10, 2) => Mar 31.
    expect($calendar->boundaryAfter(dt('2022-02-10 00:00 UTC'), 2)->toDateString())->toBe('2022-03-31');
});

it('takes hold at the next boundary when a change lands mid-interval', function (): void {
    $calendar = new Calendar(
        anchorDate: dt('2026-02-01 00:00 UTC'),
        interval: new Interval(count: 1, unit: RateCardRateBillingIntervalUnit::Month),
        timezone: 'UTC',
    );

    expect($calendar->boundaryAtOrAfter(dt('2026-02-01 00:00 UTC'))->toDateString())->toBe('2026-02-01')
        ->and($calendar->boundaryAtOrAfter(dt('2026-02-10 00:00 UTC'))->toDateString())->toBe('2026-03-01');
});

it('shares the containing interval by days, never a fixed 30', function (): void {
    $calendar = new Calendar(
        anchorDate: dt('2026-06-01 00:00 UTC'),
        interval: new Interval(count: 1, unit: RateCardRateBillingIntervalUnit::Month),
        timezone: 'UTC',
    );

    expect($calendar->prorationRatio(dt('2026-06-01 00:00 UTC'), dt('2026-07-01 00:00 UTC')))->toEqualWithDelta(1.0, 1e-12)
        ->and($calendar->prorationRatio(dt('2026-06-16 00:00 UTC'), dt('2026-07-01 00:00 UTC')))->toEqualWithDelta(0.5, 1e-12);
});

it('refuses a proration window that crosses a boundary', function (): void {
    $calendar = new Calendar(
        anchorDate: dt('2026-06-01 00:00 UTC'),
        interval: new Interval(count: 1, unit: RateCardRateBillingIntervalUnit::Month),
        timezone: 'UTC',
    );

    $calendar->prorationRatio(dt('2026-06-16 00:00 UTC'), dt('2026-07-02 00:00 UTC'));
})->throws(InvalidArgumentException::class);
