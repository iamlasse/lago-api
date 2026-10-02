<?php

declare(strict_types=1);

require_once __DIR__.'/../../../../Concerns/SubscriptionDateTestHelpers.php';

use App\Services\Subscriptions\Dates\WeeklyService;
use Carbon\CarbonImmutable;

/**
 * Port of spec/services/subscriptions/dates/weekly_service_spec.rb.
 *
 * Default lets: subscription_at 02 Feb 2021 (a Tuesday), billing_at
 * 07 Mar 2022, started_at = subscription_at, UTC.
 */
function weeklySub(array $overrides = []): App\Models\Subscription
{
    return datesSubscriptionFor('weekly', $overrides);
}

function weeklyService(App\Models\Subscription $subscription, string $billingAt, bool $currentUsage = false): WeeklyService
{
    return new WeeklyService($subscription, CarbonImmutable::parse($billingAt, 'UTC'), $currentUsage);
}

// -- from_datetime ---------------------------------------------------------------

it('returns the beginning of the previous week (calendar)', function () {
    $service = weeklyService(weeklySub(['billing_time' => 'calendar']), '2022-03-07 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2022-02-28 00:00:00');
});

it('returns nil from_datetime when not started (weekly calendar)', function () {
    $service = weeklyService(weeklySub(['billing_time' => 'calendar', 'started_at' => null]), '2022-03-07 00:00:00');

    expect($service->fromDatetime())->toBeNull();
});

it('takes the customer timezone into account on from_datetime (weekly calendar)', function () {
    $service = weeklyService(weeklySub(['billing_time' => 'calendar', 'timezone' => 'America/New_York']), '2022-03-07 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2022-02-21 05:00:00');
});

it('clamps from_datetime to the start date (weekly calendar)', function () {
    $service = weeklyService(weeklySub([
        'billing_time' => 'calendar',
        'started_at' => '2022-03-01 05:00:00',
    ]), '2022-03-07 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2022-03-01 00:00:00');
});

it('clamps from_datetime to the start date with a customer timezone (weekly calendar)', function () {
    $service = weeklyService(weeklySub([
        'billing_time' => 'calendar',
        'started_at' => '2022-03-01 05:00:00',
        'timezone' => 'America/New_York',
    ]), '2022-03-07 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2022-03-01 05:00:00');
});

it('returns the beginning of the current week for a terminated subscription (calendar)', function () {
    $subscription = weeklySub(['billing_time' => 'calendar']);
    datesTerminate($subscription, '2022-03-09 00:00:00');
    $service = weeklyService($subscription, '2022-03-10 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2022-03-07 00:00:00');
});

it('returns the beginning of the current week for a terminated pay in advance subscription (calendar)', function () {
    $subscription = weeklySub(['billing_time' => 'calendar', 'pay_in_advance' => true]);
    datesTerminate($subscription, '2022-03-09 00:00:00');
    $service = weeklyService($subscription, '2022-03-10 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2022-03-07 00:00:00');
});

it('returns the previous week weekday (anniversary)', function () {
    // subscription_at 02 Feb 2021 is a Tuesday; billing 09 Mar 2022 is a
    // Wednesday, so the base week opens on the previous Tuesday.
    $service = weeklyService(weeklySub(), '2022-03-09 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2022-03-01 00:00:00');
});

it('clamps from_datetime to the start date (weekly anniversary)', function () {
    $service = weeklyService(weeklySub(['started_at' => '2022-03-08 00:00:00']), '2022-03-09 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2022-03-08 00:00:00');
});

it('returns the current week weekday for a terminated subscription (anniversary)', function () {
    $subscription = weeklySub();
    datesTerminate($subscription, '2022-03-08 00:00:00');
    $service = weeklyService($subscription, '2022-03-09 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2022-03-08 00:00:00');
});

it('returns the current week weekday for a terminated pay in advance subscription (anniversary)', function () {
    $subscription = weeklySub(['pay_in_advance' => true]);
    datesTerminate($subscription, '2022-03-08 00:00:00');
    $service = weeklyService($subscription, '2022-03-09 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2022-03-08 00:00:00');
});

// -- to_datetime ------------------------------------------------------------------

it('returns the end of the previous week (calendar)', function () {
    $service = weeklyService(weeklySub(['billing_time' => 'calendar']), '2022-03-07 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2022-03-06 23:59:59');
});

it('returns nil to_datetime when not started (weekly)', function () {
    $service = weeklyService(weeklySub(['billing_time' => 'calendar', 'started_at' => null]), '2022-03-07 00:00:00');

    expect($service->toDatetime())->toBeNull();
});

it('takes the customer timezone into account on to_datetime (weekly calendar)', function () {
    $service = weeklyService(weeklySub(['billing_time' => 'calendar', 'timezone' => 'America/New_York']), '2022-03-07 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2022-02-28 04:59:59');
});

it('returns the end of the current week when pay in advance (calendar)', function () {
    $service = weeklyService(weeklySub(['billing_time' => 'calendar', 'pay_in_advance' => true]), '2022-03-07 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2022-03-13 23:59:59');
});

it('returns the termination date for a terminated subscription (weekly calendar)', function () {
    $subscription = weeklySub(['billing_time' => 'calendar']);
    datesTerminate($subscription, '2022-03-09 00:00:00');
    $service = weeklyService($subscription, '2022-03-10 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2022-03-09 00:00:00');
});

it('returns the end of the period day before the anniversary weekday', function () {
    $service = weeklyService(weeklySub(), '2022-03-09 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2022-03-07 23:59:59');
});

it('returns the end of the current period when pay in advance (weekly anniversary)', function () {
    $service = weeklyService(weeklySub(['pay_in_advance' => true]), '2022-03-09 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2022-03-14 23:59:59');
});

it('returns the termination date for a terminated subscription (weekly anniversary)', function () {
    $subscription = weeklySub();
    $subscription->status = 'terminated';
    $subscription->terminated_at = '2022-03-02 00:00:00';
    $subscription->save();
    $service = weeklyService($subscription, '2022-03-09 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2022-03-02 00:00:00');
});

// -- charges_from / charges_to ------------------------------------------------------

it('returns from_datetime for charges_from_datetime (weekly calendar)', function () {
    $service = weeklyService(weeklySub(['billing_time' => 'calendar']), '2022-03-07 00:00:00');

    expect($service->chargesFromDatetime()->equalTo($service->fromDatetime()))->toBeTrue();
});

it('returns the start date when the subscription started mid-week', function () {
    $service = weeklyService(weeklySub(['started_at' => '2022-03-03 00:00:00']), '2022-03-07 00:00:00');

    expect(datesUtc($service->chargesFromDatetime()))->toBe('2022-03-03 00:00:00');
});

it('returns from_datetime minus a week when pay in advance (weekly calendar)', function () {
    $service = weeklyService(weeklySub(['billing_time' => 'calendar', 'pay_in_advance' => true]), '2022-03-07 00:00:00');

    expect(datesUtc($service->chargesFromDatetime()))->toBe('2022-02-28 00:00:00');
});

it('returns from_datetime minus a week when pay in advance (weekly anniversary)', function () {
    $service = weeklyService(weeklySub(['pay_in_advance' => true]), '2022-03-09 00:00:00');

    expect(datesUtc($service->chargesFromDatetime()))->toBe('2022-03-01 00:00:00');
});

it('returns to_datetime for charges_to_datetime (weekly calendar)', function () {
    $service = weeklyService(weeklySub(['billing_time' => 'calendar']), '2022-03-07 00:00:00');

    expect($service->chargesToDatetime()->equalTo($service->toDatetime()))->toBeTrue();
});

it('returns the terminated date for charges_to_datetime mid-period', function () {
    $subscription = weeklySub(['billing_time' => 'calendar']);
    $subscription->status = 'terminated';
    $subscription->terminated_at = '2022-03-06 12:23:00';
    $subscription->save();
    $service = weeklyService($subscription, '2022-03-07 00:00:00');

    expect(datesUtc($service->chargesToDatetime()))->toBe('2022-03-06 12:23:00');
});

it('returns the end of the previous period for charges_to_datetime when pay in advance (weekly anniversary)', function () {
    $service = weeklyService(weeklySub(['pay_in_advance' => true]), '2022-03-09 00:00:00');

    $expected = CarbonImmutable::parse('2022-03-08 00:00:00', 'UTC')->subDay()->endOfDay();

    expect(datesUtc($service->chargesToDatetime()))->toBe(datesUtc($expected));
});

// -- next_end_of_period -------------------------------------------------------------

it('returns the last day of the week for next_end_of_period (calendar)', function () {
    $service = weeklyService(weeklySub(['billing_time' => 'calendar']), '2022-03-07 00:00:00');

    expect(datesUtc($service->nextEndOfPeriod()))->toBe('2022-03-13 23:59:59');
});

it('takes the customer timezone into account on next_end_of_period (weekly calendar)', function () {
    $service = weeklyService(weeklySub(['billing_time' => 'calendar', 'timezone' => 'America/New_York']), '2022-03-07 00:00:00');

    expect(datesUtc($service->nextEndOfPeriod()))->toBe('2022-03-07 04:59:59');
});

it('returns the end of the billing week (anniversary)', function () {
    $service = weeklyService(weeklySub(), '2022-03-08 20:00:00');

    expect(datesUtc($service->nextEndOfPeriod()))->toBe('2022-03-14 23:59:59');
});

it('takes the customer timezone into account on next_end_of_period (weekly anniversary)', function () {
    $service = weeklyService(weeklySub(['timezone' => 'America/New_York']), '2022-03-08 20:00:00');

    // In New York the subscription anniversary weekday is the Monday (the UTC
    // Tuesday shifts a day back).
    expect(datesUtc($service->nextEndOfPeriod()))->toBe('2022-03-14 03:59:59');
});

it('returns the billing day when it is already the end of the week period', function () {
    $service = weeklyService(weeklySub(), '2022-03-07 00:00:00');

    expect(datesUtc($service->nextEndOfPeriod()))->toBe('2022-03-07 23:59:59');
});

// -- previous_beginning_of_period -----------------------------------------------------

it('returns the first day of the previous week (weekly calendar)', function () {
    $service = weeklyService(weeklySub(['billing_time' => 'calendar']), '2022-03-07 00:00:00');

    expect(datesUtc($service->previousBeginningOfPeriod()))->toBe('2022-02-28 00:00:00');
});

it('takes the timezone into account on previous_beginning_of_period (weekly calendar)', function () {
    $service = weeklyService(weeklySub(['billing_time' => 'calendar', 'timezone' => 'America/New_York']), '2022-03-07 00:00:00');

    expect(datesUtc($service->previousBeginningOfPeriod()))->toBe('2022-02-21 05:00:00');
});

it('uses the current week when asked (weekly calendar)', function () {
    $service = weeklyService(weeklySub(['billing_time' => 'calendar']), '2022-03-07 00:00:00');

    expect(datesUtc($service->previousBeginningOfPeriod(true)))->toBe('2022-03-07 00:00:00');
});

it('returns the beginning of the previous period (weekly anniversary)', function () {
    $service = weeklyService(weeklySub(), '2022-03-09 00:00:00');

    expect(datesUtc($service->previousBeginningOfPeriod()))->toBe('2022-03-01 00:00:00');
});

it('takes the timezone into account on previous_beginning_of_period (weekly anniversary)', function () {
    $service = weeklyService(weeklySub(['timezone' => 'America/New_York']), '2022-03-09 00:00:00');

    // Billing date is Tue 03-08 in New York, where the anniversary weekday is
    // the Monday; the base week opens Mon 02-28.
    expect(datesUtc($service->previousBeginningOfPeriod()))->toBe('2022-02-28 05:00:00');
});

it('uses the current period when asked (weekly anniversary)', function () {
    $service = weeklyService(weeklySub(), '2022-03-09 00:00:00');

    expect(datesUtc($service->previousBeginningOfPeriod(true)))->toBe('2022-03-08 00:00:00');
});

// -- price / durations -----------------------------------------------------------------

it('returns the price of a single day (weekly)', function () {
    $service = weeklyService(weeklySub(['billing_time' => 'anniversary']), '2022-03-08 00:00:00');

    expect($service->singleDayPrice())->toEqual(100 / 7);
});

it('returns the duration of the period (weekly)', function () {
    $service = weeklyService(weeklySub(['billing_time' => 'anniversary']), '2022-03-08 00:00:00');

    expect($service->chargesDurationInDays())->toBe(7)
        ->and($service->fixedChargesDurationInDays())->toBe(7);
});
