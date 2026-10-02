<?php

declare(strict_types=1);

require_once __DIR__.'/../../../../Concerns/SubscriptionDateTestHelpers.php';

use Carbon\CarbonImmutable;
use App\Services\Subscriptions\Dates\QuarterlyService;

/**
 * Port of spec/services/subscriptions/dates/quarterly_service_spec.rb.
 */
function quarterlySub(array $overrides = []): App\Models\Subscription
{
    return datesSubscriptionFor('quarterly', $overrides);
}

function quarterlyService(App\Models\Subscription $subscription, string $billingAt, bool $currentUsage = false): QuarterlyService
{
    return new QuarterlyService($subscription, CarbonImmutable::parse($billingAt, 'UTC'), $currentUsage);
}

// -- from_datetime ---------------------------------------------------------------

it('returns the beginning of the previous quarter (calendar)', function () {
    $service = quarterlyService(quarterlySub(['billing_time' => 'calendar']), '2022-07-01 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2022-04-01 00:00:00');
});

it('returns nil from_datetime when not started (quarterly)', function () {
    $service = quarterlyService(quarterlySub(['billing_time' => 'calendar', 'started_at' => null]), '2022-07-01 00:00:00');

    expect($service->fromDatetime())->toBeNull();
});

it('takes the customer timezone into account on from_datetime (quarterly calendar)', function () {
    $service = quarterlyService(quarterlySub(['billing_time' => 'calendar', 'timezone' => 'America/New_York']), '2022-07-01 00:00:00');

    // Billing date is Jun 30 in New York → previous calendar quarter opens Jan 1.
    expect(datesUtc($service->fromDatetime()))->toBe('2022-01-01 05:00:00');
});

it('clamps from_datetime to the start date (quarterly calendar)', function () {
    $service = quarterlyService(quarterlySub([
        'billing_time' => 'calendar',
        'started_at' => '2022-04-07 00:00:00',
    ]), '2022-07-01 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2022-04-07 00:00:00');
});

it('returns the beginning of the quarter for a terminated subscription (calendar)', function () {
    $subscription = quarterlySub(['billing_time' => 'calendar']);
    datesTerminate($subscription, '2022-07-09 00:00:00');
    $service = quarterlyService($subscription, '2022-07-10 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2022-07-01 00:00:00');
});

it('returns the anniversary day in the previous quarter', function () {
    $service = quarterlyService(quarterlySub(), '2022-05-02 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2022-02-02 00:00:00');
});

it('walks into the previous year when the billing day is in the first quarter', function () {
    $service = quarterlyService(quarterlySub(), '2022-02-02 00:00:00');

    // Feb 2 is a non-billing month for a Feb anniversary with billing months
    // [2,5,8,11]... actually 2 IS a billing month and day matches, so the
    // previous anniversary lands in Nov 2021.
    expect(datesUtc($service->fromDatetime()))->toBe('2021-11-02 00:00:00');
});

it('clamps to the month length when the anniversary day is on the last day of a shorter month', function () {
    $subscription = quarterlySub([
        'subscription_at' => '2021-02-28 00:00:00',
        'started_at' => '2021-02-28 00:00:00',
    ]);
    $service = quarterlyService($subscription, '2022-05-31 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2022-02-28 00:00:00');
});

it('returns the current quarter anniversary day for a terminated subscription', function () {
    $subscription = quarterlySub();
    datesTerminate($subscription, '2022-05-09 00:00:00');
    $service = quarterlyService($subscription, '2022-05-10 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2022-05-02 00:00:00');
});

it('keeps the billing day when pay in advance and the date is on the last day of a shorter month', function () {
    $subscription = quarterlySub([
        'pay_in_advance' => true,
        'subscription_at' => '2021-01-31 00:00:00',
        'started_at' => '2021-01-31 00:00:00',
    ]);
    $service = quarterlyService($subscription, '2021-04-30 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2021-04-30 00:00:00');
});

// -- to_datetime -----------------------------------------------------------------

it('returns the end of the previous quarter (calendar)', function () {
    $service = quarterlyService(quarterlySub(['billing_time' => 'calendar']), '2022-07-01 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2022-06-30 23:59:59');
});

it('takes the customer timezone into account on to_datetime (quarterly calendar)', function () {
    $service = quarterlyService(quarterlySub(['billing_time' => 'calendar', 'timezone' => 'America/New_York']), '2022-07-01 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2022-04-01 03:59:59');
});

it('returns the end of the current quarter when pay in advance (calendar)', function () {
    $service = quarterlyService(quarterlySub(['billing_time' => 'calendar', 'pay_in_advance' => true]), '2022-07-01 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2022-09-30 23:59:59');
});

it('returns the day before the next anniversary (quarterly)', function () {
    $service = quarterlyService(quarterlySub(), '2022-05-02 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2022-05-01 23:59:59');
});

it('returns the day before the next anniversary when billing the last quarter month', function () {
    $service = quarterlyService(quarterlySub(), '2022-02-02 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2022-02-01 23:59:59');
});

it('handles quarterly subscription days that do not exist in the month', function () {
    $subscription = quarterlySub([
        'subscription_at' => '2021-11-30 00:00:00',
        'started_at' => '2021-11-30 00:00:00',
    ]);
    $service = quarterlyService($subscription, '2022-03-01 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2022-02-27 23:59:59');
});

it('returns the end of the quarter when the anniversary is the first day of a quarter month', function () {
    $subscription = quarterlySub([
        'subscription_at' => '2021-10-01 00:00:00',
        'started_at' => '2021-10-01 00:00:00',
    ]);
    $service = quarterlyService($subscription, '2022-04-02 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2022-03-31 23:59:59');
});

it('returns the end of the next quarter when pay in advance (quarterly)', function () {
    $service = quarterlyService(quarterlySub(['pay_in_advance' => true]), '2022-05-02 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2022-08-01 23:59:59');
});

// -- next_end_of_period ------------------------------------------------------------

it('returns the last day of the quarter for next_end_of_period (calendar)', function () {
    $service = quarterlyService(quarterlySub(['billing_time' => 'calendar']), '2022-07-02 00:00:00');

    expect(datesUtc($service->nextEndOfPeriod()))->toBe('2022-09-30 23:59:59');
});

it('takes the customer timezone into account on next_end_of_period (quarterly calendar)', function () {
    $service = quarterlyService(quarterlySub(['billing_time' => 'calendar', 'timezone' => 'America/New_York']), '2022-07-02 00:00:00');

    expect(datesUtc($service->nextEndOfPeriod()))->toBe('2022-10-01 03:59:59');
});

it('returns the end of the billing quarter (anniversary)', function () {
    $service = quarterlyService(quarterlySub(), '2022-05-07 00:00:00');

    expect(datesUtc($service->nextEndOfPeriod()))->toBe('2022-08-01 23:59:59');
});

it('takes the customer timezone into account on next_end_of_period (quarterly anniversary)', function () {
    $service = quarterlyService(quarterlySub(['timezone' => 'America/New_York']), '2022-05-07 00:00:00');

    expect(datesUtc($service->nextEndOfPeriod()))->toBe('2022-08-01 03:59:59');
});

it('walks into the next year for quarterly next_end_of_period', function () {
    $service = quarterlyService(quarterlySub(), '2021-11-02 00:00:00');

    expect(datesUtc($service->nextEndOfPeriod()))->toBe('2022-02-01 23:59:59');
});

it('returns the billing day when it already is the end of the quarter period', function () {
    $service = quarterlyService(quarterlySub(), '2022-05-01 00:00:00');

    expect(datesUtc($service->nextEndOfPeriod()))->toBe('2022-05-01 23:59:59');
});

// -- previous_beginning_of_period -----------------------------------------------------

it('returns the first day of the previous quarter (calendar)', function () {
    $service = quarterlyService(quarterlySub(['billing_time' => 'calendar']), '2022-07-07 00:00:00');

    expect(datesUtc($service->previousBeginningOfPeriod()))->toBe('2022-04-01 00:00:00');
});

it('uses the current quarter when asked (quarterly calendar)', function () {
    $service = quarterlyService(quarterlySub(['billing_time' => 'calendar']), '2022-07-07 00:00:00');

    expect(datesUtc($service->previousBeginningOfPeriod(true)))->toBe('2022-07-01 00:00:00');
});

it('returns the beginning of the previous period (quarterly anniversary)', function () {
    $service = quarterlyService(quarterlySub(), '2022-05-07 00:00:00');

    expect(datesUtc($service->previousBeginningOfPeriod()))->toBe('2022-02-02 00:00:00');
});

it('uses the current period when asked (quarterly anniversary)', function () {
    $service = quarterlyService(quarterlySub(), '2022-05-07 00:00:00');

    expect(datesUtc($service->previousBeginningOfPeriod(true)))->toBe('2022-05-02 00:00:00');
});

// -- price / durations -----------------------------------------------------------------

it('computes the single day price on a 91-day quarter (calendar)', function () {
    $service = quarterlyService(quarterlySub(['billing_time' => 'calendar']), '2022-07-01 00:00:00');

    expect($service->singleDayPrice())->toEqual(100 / 91);
});

it('computes the single day price on a leap quarter (calendar)', function () {
    $subscription = quarterlySub([
        'billing_time' => 'calendar',
        'subscription_at' => '2019-02-28 00:00:00',
        'started_at' => '2019-02-28 00:00:00',
    ]);
    $service = quarterlyService($subscription, '2020-04-01 00:00:00');

    expect($service->singleDayPrice())->toEqual(100 / 91);
});

it('prorates on the whole quarter when the subscription started mid-period (calendar)', function () {
    $subscription = quarterlySub([
        'billing_time' => 'calendar',
        'subscription_at' => '2024-01-01 00:00:00',
        'started_at' => '2024-05-20 00:00:00',
    ]);
    $service = quarterlyService($subscription, '2024-06-01 00:00:00');

    expect($service->singleDayPrice(
        CarbonImmutable::parse('2024-05-20 00:00:00', 'UTC')->startOfDay(),
    ))->toEqual(100 / 91);
});

it('computes the single day price on a 90-day quarter (anniversary, leap year)', function () {
    $service = quarterlyService(quarterlySub(), '2020-05-02 00:00:00');

    expect($service->singleDayPrice())->toEqual(100 / 90);
});

it('computes the single day price on an 89-day quarter (anniversary, common year)', function () {
    $service = quarterlyService(quarterlySub(), '2021-05-02 00:00:00');

    expect($service->singleDayPrice())->toEqual(100 / 89);
});

it('prorates on the whole quarter when started mid-period (anniversary)', function () {
    $subscription = quarterlySub([
        'subscription_at' => '2024-01-15 00:00:00',
        'started_at' => '2024-05-20 00:00:00',
    ]);
    $service = quarterlyService($subscription, '2024-06-01 00:00:00');

    expect($service->singleDayPrice(
        CarbonImmutable::parse('2024-05-20 00:00:00', 'UTC')->startOfDay(),
    ))->toEqual(100 / 91);
});

it('returns the quarter duration (calendar)', function () {
    $service = quarterlyService(quarterlySub(['billing_time' => 'calendar']), '2022-07-01 00:00:00');

    expect($service->chargesDurationInDays())->toBe(91)
        ->and($service->fixedChargesDurationInDays())->toBe(91);
});
