<?php

declare(strict_types=1);

require_once __DIR__.'/../../../../Concerns/SubscriptionDateTestHelpers.php';

use Carbon\CarbonImmutable;
use App\Services\Subscriptions\Dates\YearlyService;

/**
 * Port of spec/services/subscriptions/dates/yearly_service_spec.rb —
 * including the Feb-29 leap-year anniversary matrix.
 */
function yearlySub(array $overrides = []): App\Models\Subscription
{
    return datesSubscriptionFor('yearly', $overrides);
}

function yearlyService(App\Models\Subscription $subscription, string $billingAt, bool $currentUsage = false): YearlyService
{
    return new YearlyService($subscription, CarbonImmutable::parse($billingAt, 'UTC'), $currentUsage);
}

// -- from_datetime ---------------------------------------------------------------

it('returns the beginning of the previous year (calendar)', function (): void {
    $subscription = yearlySub([
        'billing_time' => 'calendar',
        'subscription_at' => '2019-02-02 00:00:00',
        'started_at' => '2019-02-02 00:00:00',
    ]);
    $service = yearlyService($subscription, '2022-01-01 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2021-01-01 00:00:00');
});

it('returns nil from_datetime when not started (yearly)', function (): void {
    $subscription = yearlySub(['billing_time' => 'calendar', 'started_at' => null]);
    $service = yearlyService($subscription, '2022-01-01 00:00:00');

    expect($service->fromDatetime())->toBeNull();
});

it('takes the customer timezone into account on from_datetime (yearly calendar)', function (): void {
    $subscription = yearlySub([
        'billing_time' => 'calendar',
        'subscription_at' => '2019-02-02 00:00:00',
        'started_at' => '2019-02-02 00:00:00',
        'timezone' => 'America/New_York',
    ]);
    $service = yearlyService($subscription, '2022-01-01 00:00:00');

    // Billing date is Dec 31, 2021 in New York → previous year opens Jan 1, 2020 NY.
    expect(datesUtc($service->fromDatetime()))->toBe('2020-01-01 05:00:00');
});

it('clamps from_datetime to the start date (yearly calendar)', function (): void {
    $subscription = yearlySub([
        'billing_time' => 'calendar',
        'subscription_at' => '2019-02-02 00:00:00',
        'started_at' => '2021-02-07 00:00:00',
    ]);
    $service = yearlyService($subscription, '2022-01-01 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2021-02-07 00:00:00');
});

it('clamps from_datetime to the start date with a customer timezone (yearly calendar)', function (): void {
    $subscription = yearlySub([
        'billing_time' => 'calendar',
        'subscription_at' => '2019-02-02 00:00:00',
        'started_at' => '2021-02-07 00:00:00',
        'timezone' => 'America/New_York',
    ]);
    $service = yearlyService($subscription, '2022-01-01 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2021-02-06 05:00:00');
});

it('returns the beginning of the year for a terminated subscription (calendar)', function (): void {
    $subscription = yearlySub([
        'billing_time' => 'calendar',
        'subscription_at' => '2019-02-02 00:00:00',
        'started_at' => '2019-02-02 00:00:00',
    ]);
    datesTerminate($subscription, '2022-03-09 00:00:00');
    $service = yearlyService($subscription, '2022-03-10 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2022-01-01 00:00:00');
});

it('returns the previous year anniversary day', function (): void {
    $subscription = yearlySub();
    $service = yearlyService($subscription, '2022-02-02 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2021-02-02 00:00:00');
});

it('resolves the previous anniversary with current usage in the anniversary month', function (): void {
    $subscription = yearlySub([
        'subscription_at' => '2023-03-29 00:00:00',
        'started_at' => '2023-03-29 00:00:00',
    ]);
    $service = yearlyService($subscription, '2024-03-15 00:00:00', true);

    expect(datesUtc($service->fromDatetime()))->toBe('2023-03-29 00:00:00');
});

it('clamps from_datetime to the start date (yearly anniversary)', function (): void {
    $subscription = yearlySub(['started_at' => '2022-09-02 00:00:00']);
    $service = yearlyService($subscription, '2022-02-02 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2022-09-02 00:00:00');
});

it('returns the leap day of the previous period for a Feb-29 subscription', function (): void {
    $subscription = yearlySub([
        'subscription_at' => '2020-02-29 00:00:00',
        'started_at' => '2020-02-29 00:00:00',
    ]);
    $service = yearlyService($subscription, '2025-02-28 00:00:00');

    // The period being billed opened on the leap day (Feb 29, 2024).
    expect(datesUtc($service->fromDatetime()))->toBe('2024-02-29 00:00:00');
});

it('returns the anniversary day for a terminated subscription (yearly)', function (): void {
    $subscription = yearlySub();
    datesTerminate($subscription, '2022-02-01 00:00:00');
    $service = yearlyService($subscription, '2022-02-02 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2022-02-02 00:00:00');
});

it('returns the current year anniversary day for a terminated pay-in-advance subscription', function (): void {
    $subscription = yearlySub(['pay_in_advance' => true]);
    datesTerminate($subscription, '2022-02-01 00:00:00');
    $service = yearlyService($subscription, '2022-02-02 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2022-02-02 00:00:00');
});

it('clamps a Feb-29 anniversary to the common-year last day of February when terminated', function (): void {
    $subscription = yearlySub([
        'subscription_at' => '2020-02-29 00:00:00',
        'started_at' => '2020-02-29 00:00:00',
    ]);
    datesTerminate($subscription, '2022-02-01 00:00:00');
    $service = yearlyService($subscription, '2022-03-28 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2022-02-28 00:00:00');
});

it('walks into the previous year when the billing month is before the anniversary month', function (): void {
    $subscription = yearlySub();
    datesTerminate($subscription, '2022-02-01 00:00:00');
    $service = yearlyService($subscription, '2022-01-03 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2021-02-02 00:00:00');
});

// -- to_datetime -----------------------------------------------------------------

it('returns the end of the previous year (calendar)', function (): void {
    $subscription = yearlySub([
        'billing_time' => 'calendar',
        'subscription_at' => '2020-02-02 00:00:00',
        'started_at' => '2020-02-02 00:00:00',
    ]);
    $service = yearlyService($subscription, '2022-01-01 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2021-12-31 23:59:59');
});

it('takes the customer timezone into account on to_datetime (yearly calendar)', function (): void {
    $subscription = yearlySub([
        'billing_time' => 'calendar',
        'subscription_at' => '2020-02-02 00:00:00',
        'started_at' => '2020-02-02 00:00:00',
        'timezone' => 'America/New_York',
    ]);
    $service = yearlyService($subscription, '2022-01-01 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2021-01-01 04:59:59');
});

it('returns the end of the current year when pay in advance (calendar)', function (): void {
    $subscription = yearlySub([
        'billing_time' => 'calendar',
        'subscription_at' => '2020-02-02 00:00:00',
        'started_at' => '2020-02-02 00:00:00',
        'pay_in_advance' => true,
    ]);
    $service = yearlyService($subscription, '2022-01-01 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2022-12-31 23:59:59');
});

it('returns the termination date for a subscription terminated in the period (calendar)', function (): void {
    $subscription = yearlySub([
        'billing_time' => 'calendar',
        'subscription_at' => '2020-02-02 00:00:00',
        'started_at' => '2020-02-02 00:00:00',
    ]);
    datesTerminate($subscription, '2022-03-02 00:00:00');
    $service = yearlyService($subscription, '2022-03-10 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2022-03-02 00:00:00');
});

it('returns the day before the anniversary (yearly)', function (): void {
    $service = yearlyService(yearlySub(), '2022-02-02 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2022-02-01 23:59:59');
});

it('returns the day before the clamped Feb-29 anniversary in a common year', function (): void {
    $subscription = yearlySub([
        'subscription_at' => '2020-02-29 00:00:00',
        'started_at' => '2020-02-29 00:00:00',
    ]);
    $service = yearlyService($subscription, '2022-03-01 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2022-02-27 23:59:59');
});

it('closes the leap-day period when billing on the clamped anniversary', function (): void {
    $subscription = yearlySub([
        'subscription_at' => '2020-02-29 00:00:00',
        'started_at' => '2020-02-29 00:00:00',
    ]);
    $service = yearlyService($subscription, '2025-02-28 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2025-02-27 23:59:59');
});

it('returns the last day of the year when the anniversary is the first day of the year', function (): void {
    $subscription = yearlySub([
        'subscription_at' => '2021-01-01 00:00:00',
        'started_at' => '2021-01-01 00:00:00',
    ]);
    $service = yearlyService($subscription, '2022-03-02 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2021-12-31 23:59:59');
});

it('builds the period end from day zero when the anniversary is the first of a month', function (): void {
    $subscription = yearlySub([
        'subscription_at' => '2022-12-01 00:00:00',
        'started_at' => '2022-12-01 00:00:00',
    ]);
    $service = yearlyService($subscription, '2024-01-02 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2023-11-30 23:59:59');
});

it('returns the end of the next period when pay in advance (yearly)', function (): void {
    $service = yearlyService(yearlySub(['pay_in_advance' => true]), '2022-02-02 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2023-02-01 23:59:59');
});

it('returns the termination date (yearly anniversary)', function (): void {
    $subscription = yearlySub();
    datesTerminate($subscription, '2022-01-02 00:00:00');
    $service = yearlyService($subscription, '2022-02-02 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2022-01-02 00:00:00');
});

// -- next_end_of_period ------------------------------------------------------------

it('returns the last day of the year for next_end_of_period (calendar)', function (): void {
    $service = yearlyService(yearlySub(['billing_time' => 'calendar']), '2022-03-07 00:00:00');

    expect(datesUtc($service->nextEndOfPeriod()))->toBe('2022-12-31 23:59:59');
});

it('takes the customer timezone into account on next_end_of_period (yearly calendar)', function (): void {
    $service = yearlyService(yearlySub(['billing_time' => 'calendar', 'timezone' => 'America/New_York']), '2022-03-07 00:00:00');

    expect(datesUtc($service->nextEndOfPeriod()))->toBe('2023-01-01 04:59:59');
});

it('returns the end of the billing year (anniversary)', function (): void {
    $service = yearlyService(yearlySub(), '2022-03-07 00:00:00');

    expect(datesUtc($service->nextEndOfPeriod()))->toBe('2023-02-01 23:59:59');
});

it('takes the customer timezone into account on next_end_of_period (yearly anniversary)', function (): void {
    $service = yearlyService(yearlySub(['timezone' => 'America/New_York']), '2022-03-07 00:00:00');

    expect(datesUtc($service->nextEndOfPeriod()))->toBe('2023-02-01 04:59:59');
});

it('returns the billing day when it already is the end of the year period', function (): void {
    $service = yearlyService(yearlySub(), '2022-02-01 00:00:00');

    expect(datesUtc($service->nextEndOfPeriod()))->toBe('2022-02-01 23:59:59');
});

// -- previous_beginning_of_period -----------------------------------------------------

it('returns the first day of the previous year (calendar)', function (): void {
    $service = yearlyService(yearlySub(['billing_time' => 'calendar']), '2022-03-07 00:00:00');

    expect(datesUtc($service->previousBeginningOfPeriod()))->toBe('2021-01-01 00:00:00');
});

it('takes the timezone into account on previous_beginning_of_period (yearly calendar)', function (): void {
    $service = yearlyService(yearlySub(['billing_time' => 'calendar', 'timezone' => 'America/New_York']), '2022-03-07 00:00:00');

    expect(datesUtc($service->previousBeginningOfPeriod()))->toBe('2021-01-01 05:00:00');
});

it('uses the current year when asked (yearly calendar)', function (): void {
    $service = yearlyService(yearlySub(['billing_time' => 'calendar']), '2022-03-07 00:00:00');

    expect(datesUtc($service->previousBeginningOfPeriod(true)))->toBe('2022-01-01 00:00:00');
});

it('returns the beginning of the previous period (yearly anniversary)', function (): void {
    $service = yearlyService(yearlySub(), '2022-03-07 00:00:00');

    expect(datesUtc($service->previousBeginningOfPeriod()))->toBe('2021-02-02 00:00:00');
});

it('takes the timezone into account on previous_beginning_of_period (yearly anniversary)', function (): void {
    $service = yearlyService(yearlySub(['timezone' => 'America/New_York']), '2022-03-07 00:00:00');

    expect(datesUtc($service->previousBeginningOfPeriod()))->toBe('2021-02-01 05:00:00');
});

it('uses the current period when asked (yearly anniversary)', function (): void {
    $service = yearlyService(yearlySub(), '2022-03-07 00:00:00');

    expect(datesUtc($service->previousBeginningOfPeriod(true)))->toBe('2022-02-02 00:00:00');
});

// -- price / durations -----------------------------------------------------------------

it('computes the single day price on a 365-day year (calendar)', function (): void {
    $service = yearlyService(yearlySub(['billing_time' => 'calendar']), '2022-03-07 00:00:00');

    expect($service->singleDayPrice())->toEqual(100 / 365);
});

it('computes the single day price on a 366-day leap year (calendar)', function (): void {
    $subscription = yearlySub([
        'billing_time' => 'calendar',
        'subscription_at' => '2019-02-28 00:00:00',
        'started_at' => '2019-02-28 00:00:00',
    ]);
    $service = yearlyService($subscription, '2021-01-01 00:00:00');

    expect($service->singleDayPrice())->toEqual(100 / 366);
});

it('computes the single day price on a 365-day year (anniversary)', function (): void {
    $service = yearlyService(yearlySub(), '2022-03-07 00:00:00');

    expect($service->singleDayPrice())->toEqual(100 / 365);
});

it('computes the single day price on a 366-day leap year (anniversary)', function (): void {
    $subscription = yearlySub([
        'subscription_at' => '2019-02-02 00:00:00',
        'started_at' => '2019-02-02 00:00:00',
    ]);
    $service = yearlyService($subscription, '2021-03-08 00:00:00');

    expect($service->singleDayPrice())->toEqual(100 / 366);
});

it('computes 365 days for the period opened on a leap day', function (): void {
    $subscription = yearlySub([
        'subscription_at' => '2020-02-29 00:00:00',
        'started_at' => '2020-02-29 00:00:00',
    ]);
    $service = yearlyService($subscription, '2025-02-28 00:00:00');

    // Feb 29 2024 → Feb 27 2025 is 365 days, not the 366 of the leap year it starts in.
    expect($service->singleDayPrice())->toEqual(100 / 365);
});

it('prorates on the whole 366-day period when started mid-period (Jan anniversary)', function (): void {
    $subscription = yearlySub([
        'subscription_at' => '2024-01-01 00:00:00',
        'started_at' => '2024-10-10 00:00:00',
    ]);
    $service = yearlyService($subscription, '2024-11-01 00:00:00');

    expect($service->singleDayPrice(
        CarbonImmutable::parse('2024-10-10 00:00:00', 'UTC')->startOfDay(),
    ))->toEqual(100 / 366);
});

it('prorates on the whole 365-day period when started mid-period (Mar anniversary)', function (): void {
    $subscription = yearlySub([
        'subscription_at' => '2024-03-15 00:00:00',
        'started_at' => '2024-10-10 00:00:00',
    ]);
    $service = yearlyService($subscription, '2024-11-01 00:00:00');

    expect($service->singleDayPrice(
        CarbonImmutable::parse('2024-10-10 00:00:00', 'UTC')->startOfDay(),
    ))->toEqual(100 / 365);
});

it('returns the year duration (calendar)', function (): void {
    $service = yearlyService(yearlySub(['billing_time' => 'calendar']), '2022-03-07 00:00:00');

    expect($service->chargesDurationInDays())->toBe(365)
        ->and($service->fixedChargesDurationInDays())->toBe(365);
});

it('returns the leap year duration (calendar)', function (): void {
    $subscription = yearlySub([
        'billing_time' => 'calendar',
        'subscription_at' => '2019-02-28 00:00:00',
        'started_at' => '2019-02-28 00:00:00',
    ]);
    $service = yearlyService($subscription, '2021-01-01 00:00:00');

    expect($service->chargesDurationInDays())->toBe(366)
        ->and($service->fixedChargesDurationInDays())->toBe(366);
});

it('returns the month duration when charges bill monthly', function (): void {
    $subscription = yearlySub(['billing_time' => 'calendar', 'bill_charges_monthly' => true]);
    $service = yearlyService($subscription, '2022-03-07 00:00:00');

    expect($service->chargesDurationInDays())->toBe(28);
});

it('returns the month duration for fixed charges when they bill monthly', function (): void {
    $subscription = yearlySub(['billing_time' => 'calendar', 'bill_fixed_charges_monthly' => true]);
    $service = yearlyService($subscription, '2022-03-07 00:00:00');

    expect($service->fixedChargesDurationInDays())->toBe(28);
});

it('gates charge boundaries to the first month of the yearly period', function (): void {
    // bill_charges_monthly=false + bill_fixed_charges_monthly=true:
    // charge boundaries only fill in the first month (January, calendar) of
    // the yearly period.
    $subscription = yearlySub([
        'billing_time' => 'calendar',
        'subscription_at' => '2022-02-02 00:00:00',
        'started_at' => '2022-02-02 00:00:00',
        'bill_charges_monthly' => false,
        'bill_fixed_charges_monthly' => true,
    ]);

    $inFirstMonth = yearlyService($subscription, '2022-01-07 00:00:00');
    $outside = yearlyService($subscription, '2022-07-07 00:00:00');

    expect($inFirstMonth->chargesFromDatetime())->not->toBeNull()
        ->and($outside->chargesFromDatetime())->toBeNull();
});

it('gates fixed charge boundaries to the first month when fixed charges bill monthly', function (): void {
    $subscription = yearlySub([
        'billing_time' => 'calendar',
        'subscription_at' => '2022-02-02 00:00:00',
        'started_at' => '2022-02-02 00:00:00',
        'bill_charges_monthly' => true,
        'bill_fixed_charges_monthly' => false,
    ]);

    $inFirstMonth = yearlyService($subscription, '2022-01-07 00:00:00');
    $outside = yearlyService($subscription, '2022-07-07 00:00:00');

    expect($inFirstMonth->fixedChargesToDatetime())->not->toBeNull()
        ->and($outside->fixedChargesToDatetime())->toBeNull();
});
