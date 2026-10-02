<?php

declare(strict_types=1);

require_once __DIR__.'/../../../../Concerns/SubscriptionDateTestHelpers.php';

use App\Services\Subscriptions\Dates\SemiannualService;
use Carbon\CarbonImmutable;

/**
 * Port of spec/services/subscriptions/dates/semiannual_service_spec.rb.
 */
function semiannualSub(array $overrides = []): App\Models\Subscription
{
    return datesSubscriptionFor('semiannual', $overrides);
}

function semiannualService(App\Models\Subscription $subscription, string $billingAt, bool $currentUsage = false): SemiannualService
{
    return new SemiannualService($subscription, CarbonImmutable::parse($billingAt, 'UTC'), $currentUsage);
}

// -- from_datetime ---------------------------------------------------------------

it('returns the beginning of the previous half year (calendar)', function () {
    $service = semiannualService(semiannualSub(['billing_time' => 'calendar']), '2022-07-01 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2022-01-01 00:00:00');
});

it('returns nil from_datetime when not started (semiannual)', function () {
    $service = semiannualService(semiannualSub(['billing_time' => 'calendar', 'started_at' => null]), '2022-07-01 00:00:00');

    expect($service->fromDatetime())->toBeNull();
});

it('takes the customer timezone into account on from_datetime (semiannual calendar)', function () {
    $service = semiannualService(semiannualSub(['billing_time' => 'calendar', 'timezone' => 'America/New_York']), '2022-07-01 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2021-07-01 04:00:00');
});

it('clamps from_datetime to the start date (semiannual calendar)', function () {
    $service = semiannualService(semiannualSub([
        'billing_time' => 'calendar',
        'started_at' => '2022-04-07 00:00:00',
    ]), '2022-07-01 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2022-04-07 00:00:00');
});

it('returns the beginning of the half year for a terminated subscription (calendar)', function () {
    $subscription = semiannualSub(['billing_time' => 'calendar']);
    datesTerminate($subscription, '2022-07-09 00:00:00');
    $service = semiannualService($subscription, '2022-07-10 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2022-07-01 00:00:00');
});

it('returns the previous half-year anniversary day', function () {
    $subscription = semiannualSub([
        'subscription_at' => '2021-11-02 00:00:00',
        'started_at' => '2021-11-02 00:00:00',
    ]);
    $service = semiannualService($subscription, '2022-05-02 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2021-11-02 00:00:00');
});

it('resolves the previous period anniversary on the last day of a shorter month', function () {
    $subscription = semiannualSub([
        'subscription_at' => '2021-11-30 00:00:00',
        'started_at' => '2021-11-30 00:00:00',
    ]);
    $service = semiannualService($subscription, '2022-05-31 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2021-11-30 00:00:00');
});

it('returns the current half-year anniversary day for a terminated subscription', function () {
    $subscription = semiannualSub([
        'subscription_at' => '2021-11-02 00:00:00',
        'started_at' => '2021-11-02 00:00:00',
    ]);
    datesTerminate($subscription, '2022-05-09 00:00:00');
    $service = semiannualService($subscription, '2022-05-10 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2022-05-02 00:00:00');
});

it('resolves the previous-year half when the terminated billing day is in the second month', function () {
    $subscription = semiannualSub([
        'subscription_at' => '2021-02-28 00:00:00',
        'started_at' => '2021-02-28 00:00:00',
    ]);
    datesTerminate($subscription, '2022-03-09 00:00:00'); // enclosing terminate first
    datesTerminate($subscription, '2022-02-27 00:00:00');
    $service = semiannualService($subscription, '2022-02-27 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2021-08-28 00:00:00');
});

it('keeps the pay-in-advance billing day on the last day of a shorter month', function () {
    $subscription = semiannualSub([
        'pay_in_advance' => true,
        'subscription_at' => '2021-01-31 00:00:00',
        'started_at' => '2021-01-31 00:00:00',
    ]);
    $service = semiannualService($subscription, '2021-04-30 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2021-01-31 00:00:00');
});

it('resolves a non-billing month to the current half-year anniversary (current usage)', function () {
    $subscription = semiannualSub([
        'subscription_at' => '2023-01-06 00:00:00',
        'started_at' => '2023-01-06 00:00:00',
    ]);
    $service = semiannualService($subscription, '2023-08-08 00:00:00', true);

    expect(datesUtc($service->fromDatetime()))->toBe('2023-07-06 00:00:00');
});

it('resolves the anniversary when the day is before the subscription day (current usage)', function () {
    $subscription = semiannualSub([
        'subscription_at' => '2023-01-06 00:00:00',
        'started_at' => '2023-01-06 00:00:00',
    ]);
    $service = semiannualService($subscription, '2023-08-04 00:00:00', true);

    expect(datesUtc($service->fromDatetime()))->toBe('2023-07-06 00:00:00');
});

// -- to_datetime -----------------------------------------------------------------

it('returns the end of the previous half year (calendar)', function () {
    $service = semiannualService(semiannualSub(['billing_time' => 'calendar']), '2022-07-01 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2022-06-30 23:59:59');
});

it('takes the customer timezone into account on to_datetime (semiannual calendar)', function () {
    $service = semiannualService(semiannualSub(['billing_time' => 'calendar', 'timezone' => 'America/New_York']), '2022-07-01 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2022-01-01 03:59:59');
});

it('returns the end of the current half year when pay in advance (calendar)', function () {
    $service = semiannualService(semiannualSub(['billing_time' => 'calendar', 'pay_in_advance' => true]), '2022-07-01 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2022-12-31 23:59:59');
});

it('returns the day before the next half-year anniversary', function () {
    $subscription = semiannualSub([
        'subscription_at' => '2021-11-02 00:00:00',
        'started_at' => '2021-11-02 00:00:00',
    ]);
    $service = semiannualService($subscription, '2022-05-02 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2022-05-01 23:59:59');
});

it('returns the termination date for a subscription terminated on the anniversary day', function () {
    $subscription = semiannualSub([
        'subscription_at' => '2021-11-02 00:00:00',
        'started_at' => '2021-11-02 00:00:00',
    ]);
    datesTerminate($subscription, '2022-05-02 00:00:00');
    $service = semiannualService($subscription, '2022-05-10 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2022-05-02 00:00:00');
});

// -- fixed charge gating (bill_fixed_charges_monthly) -------------------------------

it('returns nil fixed charge boundaries outside the first month of the half when billing monthly', function () {
    $subscription = semiannualSub([
        'billing_time' => 'calendar',
        'bill_fixed_charges_monthly' => true,
        'bill_charges_monthly' => false,
    ]);
    $service = semiannualService($subscription, '2022-03-07 00:00:00');

    expect($service->fixedChargesFromDatetime())->toBeNull()
        ->and($service->fixedChargesToDatetime())->toBeNull();
});

it('returns fixed charge boundaries in the first month of the half when billing monthly', function () {
    $subscription = semiannualSub([
        'billing_time' => 'calendar',
        'bill_fixed_charges_monthly' => true,
        'bill_charges_monthly' => false,
    ]);
    $service = semiannualService($subscription, '2022-01-07 00:00:00');

    expect($service->fixedChargesFromDatetime())->not->toBeNull()
        ->and($service->fixedChargesToDatetime())->not->toBeNull();
});

it('still returns the period end via fixed_charges_period_to_datetime even when boundaries are nil', function () {
    $subscription = semiannualSub([
        'billing_time' => 'calendar',
        'bill_fixed_charges_monthly' => true,
        'bill_charges_monthly' => false,
    ]);
    $service = semiannualService($subscription, '2022-03-07 00:00:00');

    expect($service->fixedChargesToDatetime())->toBeNull();

    $periodEnd = $service->fixedChargesPeriodToDatetime();
    expect($periodEnd)->not->toBeNull();
});

// -- next_end_of_period ------------------------------------------------------------

it('returns the last day of the half year for next_end_of_period (calendar)', function () {
    $service = semiannualService(semiannualSub(['billing_time' => 'calendar']), '2022-07-07 00:00:00');

    expect(datesUtc($service->nextEndOfPeriod()))->toBe('2022-12-31 23:59:59');
});

it('takes the customer timezone into account on next_end_of_period (semiannual calendar)', function () {
    $service = semiannualService(semiannualSub(['billing_time' => 'calendar', 'timezone' => 'America/New_York']), '2022-07-07 00:00:00');

    expect(datesUtc($service->nextEndOfPeriod()))->toBe('2023-01-01 04:59:59');
});

it('returns the end of the billing half year (anniversary)', function () {
    $service = semiannualService(semiannualSub([
        'subscription_at' => '2021-11-02 00:00:00',
        'started_at' => '2021-11-02 00:00:00',
    ]), '2022-05-07 00:00:00');

    expect(datesUtc($service->nextEndOfPeriod()))->toBe('2022-11-01 23:59:59');
});

it('walks into the next year for semiannual next_end_of_period', function () {
    $service = semiannualService(semiannualSub([
        'subscription_at' => '2021-11-02 00:00:00',
        'started_at' => '2021-11-02 00:00:00',
    ]), '2022-10-02 00:00:00');

    expect(datesUtc($service->nextEndOfPeriod()))->toBe('2023-05-01 23:59:59');
});

it('returns the billing day when it already is the end of the half-year period', function () {
    $service = semiannualService(semiannualSub([
        'subscription_at' => '2021-11-02 00:00:00',
        'started_at' => '2021-11-02 00:00:00',
    ]), '2022-05-01 00:00:00');

    expect(datesUtc($service->nextEndOfPeriod()))->toBe('2022-05-01 23:59:59');
});

// -- previous_beginning_of_period -----------------------------------------------------

it('returns the first day of the previous half year (calendar)', function () {
    $service = semiannualService(semiannualSub(['billing_time' => 'calendar']), '2022-07-07 00:00:00');

    expect(datesUtc($service->previousBeginningOfPeriod()))->toBe('2022-01-01 00:00:00');
});

it('takes the timezone into account on previous_beginning_of_period (semiannual calendar)', function () {
    $service = semiannualService(semiannualSub(['billing_time' => 'calendar', 'timezone' => 'America/New_York']), '2022-07-07 00:00:00');

    expect(datesUtc($service->previousBeginningOfPeriod()))->toBe('2022-01-01 05:00:00');
});

it('uses the current half year when asked (semiannual calendar)', function () {
    $service = semiannualService(semiannualSub(['billing_time' => 'calendar']), '2022-07-07 00:00:00');

    expect(datesUtc($service->previousBeginningOfPeriod(true)))->toBe('2022-07-01 00:00:00');
});

it('returns the beginning of the previous period (semiannual anniversary)', function () {
    $service = semiannualService(semiannualSub([
        'subscription_at' => '2021-11-02 00:00:00',
        'started_at' => '2021-11-02 00:00:00',
    ]), '2022-05-07 00:00:00');

    expect(datesUtc($service->previousBeginningOfPeriod()))->toBe('2021-11-02 00:00:00');
});

it('uses the current period when asked (semiannual anniversary)', function () {
    $service = semiannualService(semiannualSub([
        'subscription_at' => '2021-11-02 00:00:00',
        'started_at' => '2021-11-02 00:00:00',
    ]), '2022-05-07 00:00:00');

    expect(datesUtc($service->previousBeginningOfPeriod(true)))->toBe('2022-05-02 00:00:00');
});

// -- price / durations -----------------------------------------------------------------

it('computes the single day price on a 181-day half year (calendar)', function () {
    $service = semiannualService(semiannualSub(['billing_time' => 'calendar']), '2022-07-01 00:00:00');

    expect($service->singleDayPrice())->toEqual(100 / 181);
});

it('computes the single day price on a 182-day leap half year (calendar)', function () {
    $subscription = semiannualSub([
        'billing_time' => 'calendar',
        'subscription_at' => '2019-02-28 00:00:00',
        'started_at' => '2019-02-28 00:00:00',
    ]);
    $service = semiannualService($subscription, '2020-07-01 00:00:00');

    expect($service->singleDayPrice())->toEqual(100 / 182);
});

it('computes the single day price on a 181-day anniversary half', function () {
    $subscription = semiannualSub([
        'subscription_at' => '2021-11-02 00:00:00',
        'started_at' => '2021-11-02 00:00:00',
    ]);
    $service = semiannualService($subscription, '2022-05-02 00:00:00');

    expect($service->singleDayPrice())->toEqual(100 / 181);
});

it('prorates on the whole half when started mid-period', function () {
    $subscription = semiannualSub([
        'subscription_at' => '2024-01-15 00:00:00',
        'started_at' => '2024-05-20 00:00:00',
    ]);
    $service = semiannualService($subscription, '2024-06-01 00:00:00');

    expect($service->singleDayPrice(
        CarbonImmutable::parse('2024-05-20 00:00:00', 'UTC')->startOfDay(),
    ))->toEqual(100 / 182);
});

it('returns the half-year duration (calendar)', function () {
    $service = semiannualService(semiannualSub(['billing_time' => 'calendar']), '2022-07-01 00:00:00');

    expect($service->chargesDurationInDays())->toBe(181)
        ->and($service->fixedChargesDurationInDays())->toBe(181);
});
