<?php

declare(strict_types=1);

require_once __DIR__.'/../../../../Concerns/SubscriptionDateTestHelpers.php';

use App\Models\Customer;
use Carbon\CarbonImmutable;
use App\Services\Subscriptions\DatesService;
use App\Services\Subscriptions\Dates\MonthlyService;

/**
 * Port of spec/services/subscriptions/dates/monthly_service_spec.rb.
 *
 * Default lets: subscription_at 02 Feb 2021, billing_at 07 Mar 2022,
 * started_at = subscription_at, UTC, pay_in_advance false.
 */
function monthlySub(array $overrides = []): App\Models\Subscription
{
    return datesSubscriptionFor('monthly', $overrides);
}

function monthlyService(App\Models\Subscription $subscription, string $billingAt, bool $currentUsage = false): MonthlyService
{
    return new MonthlyService($subscription, CarbonImmutable::parse($billingAt, 'UTC'), $currentUsage);
}

it('resolves the monthly service through new_instance', function (): void {
    $subscription = monthlySub(['billing_time' => 'calendar']);

    $service = DatesService::newInstance($subscription, Date::parse('2022-03-07 00:00:00', 'UTC'));

    expect($service)->toBeInstanceOf(MonthlyService::class);
});

// -- from_datetime -------------------------------------------------------------

it('computes from_datetime as the beginning of the previous month (calendar)', function (): void {
    $subscription = monthlySub(['billing_time' => 'calendar']);
    $service = monthlyService($subscription, '2022-03-01 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2022-02-01 00:00:00');
});

it('returns nil from_datetime when the subscription is not started (calendar)', function (): void {
    $subscription = monthlySub(['billing_time' => 'calendar', 'started_at' => null]);
    $service = monthlyService($subscription, '2022-03-01 00:00:00');

    expect($service->fromDatetime())->toBeNull();
});

it('takes the customer timezone into account on from_datetime (calendar)', function (): void {
    $subscription = monthlySub(['billing_time' => 'calendar', 'timezone' => 'America/New_York']);
    $service = monthlyService($subscription, '2022-03-01 00:00:00');

    // billing_date is Feb 28 in New York, so the previous month opens Jan 1.
    expect(datesUtc($service->fromDatetime()))->toBe('2022-01-01 05:00:00');
});

it('clamps from_datetime to the start date (calendar)', function (): void {
    $subscription = monthlySub([
        'billing_time' => 'calendar',
        'started_at' => '2022-02-07 05:00:00',
    ]);
    $service = monthlyService($subscription, '2022-03-01 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2022-02-07 00:00:00');
});

it('clamps from_datetime to the start date with a customer timezone (calendar)', function (): void {
    $subscription = monthlySub([
        'billing_time' => 'calendar',
        'started_at' => '2022-02-07 05:00:00',
        'timezone' => 'America/New_York',
    ]);
    $service = monthlyService($subscription, '2022-03-01 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2022-02-07 05:00:00');
});

it('returns the beginning of the month for a just terminated subscription (calendar)', function (): void {
    $subscription = monthlySub(['billing_time' => 'calendar']);
    datesTerminate($subscription, '2022-03-09 00:00:00');
    $service = monthlyService($subscription, '2022-03-10 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2022-03-01 00:00:00');
});

it('returns the beginning of the month for a just terminated pay in advance subscription (calendar)', function (): void {
    $subscription = monthlySub(['billing_time' => 'calendar', 'pay_in_advance' => true]);
    datesTerminate($subscription, '2022-03-09 00:00:00');
    $service = monthlyService($subscription, '2022-03-10 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2022-03-01 00:00:00');
});

it('computes from_datetime as the previous month anniversary day', function (): void {
    $subscription = monthlySub();
    $service = monthlyService($subscription, '2022-03-02 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2022-02-02 00:00:00');
});

it('clamps from_datetime to the start date (anniversary)', function (): void {
    $subscription = monthlySub(['started_at' => '2022-02-08 00:00:00']);
    $service = monthlyService($subscription, '2022-03-02 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2022-02-08 00:00:00');
});

it('returns the current month anniversary day for a just terminated subscription', function (): void {
    $subscription = monthlySub();
    datesTerminate($subscription, '2022-03-09 00:00:00');
    $service = monthlyService($subscription, '2022-03-10 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2022-03-02 00:00:00');
});

it('returns the current month anniversary day for a just terminated pay in advance subscription', function (): void {
    $subscription = monthlySub(['pay_in_advance' => true]);
    datesTerminate($subscription, '2022-03-09 00:00:00');
    $service = monthlyService($subscription, '2022-03-10 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2022-03-02 00:00:00');
});

it('returns the previous month last day when the billing day is after the anniversary month length', function (): void {
    $subscription = monthlySub([
        'subscription_at' => '2021-03-31 00:00:00',
        'started_at' => '2021-03-31 00:00:00',
    ]);
    datesTerminate($subscription, '2022-03-09 00:00:00');
    $service = monthlyService($subscription, '2022-03-29 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2022-02-28 00:00:00');
});

it('walks into the previous year when the terminated billing day is in the first month', function (): void {
    $subscription = monthlySub([
        'subscription_at' => '2021-03-27 00:00:00',
        'started_at' => '2021-03-27 00:00:00',
    ]);
    // Rails: the enclosing context terminated on 9 Mar first — mark_as_terminated!
    // keeps that terminated_at (||=), so the Jan billing day is *not* reached by
    // the termination and the previous period start comes from base_date.
    datesTerminate($subscription, '2022-03-09 00:00:00');
    datesTerminate($subscription, '2022-01-27 00:00:00');
    $service = monthlyService($subscription, '2022-01-28 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2021-12-27 00:00:00');
});

it('keeps the billing day when pay in advance and the date is on the last day of a shorter month', function (): void {
    $subscription = monthlySub([
        'pay_in_advance' => true,
        'subscription_at' => '2021-03-31 00:00:00',
        'started_at' => '2021-03-31 00:00:00',
    ]);
    $service = monthlyService($subscription, '2021-04-30 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2021-04-30 00:00:00');
});

it('keeps the leap day when pay in advance and the billing month is longer', function (): void {
    $subscription = monthlySub([
        'pay_in_advance' => true,
        'subscription_at' => '2020-01-31 00:00:00',
        'started_at' => '2020-01-31 00:00:00',
    ]);
    $service = monthlyService($subscription, '2020-02-29 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2020-02-29 00:00:00');
});

it('returns the current billing day in arrear when the date is on the last day of a shorter month', function (): void {
    $subscription = monthlySub([
        'subscription_at' => '2021-03-31 00:00:00',
        'started_at' => '2021-03-31 00:00:00',
    ]);
    $service = monthlyService($subscription, '2021-04-30 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2021-03-31 00:00:00');
});

it('returns the clamped anniversary day in arrear when the subscription month is shorter', function (): void {
    $subscription = monthlySub([
        'subscription_at' => '2019-04-30 00:00:00',
        'started_at' => '2019-04-30 00:00:00',
    ]);
    $service = monthlyService($subscription, '2020-03-30 00:00:00');

    expect(datesUtc($service->fromDatetime()))->toBe('2020-02-29 00:00:00');
});

// -- to_datetime ---------------------------------------------------------------

it('computes to_datetime as the end of the previous month (calendar)', function (): void {
    $subscription = monthlySub(['billing_time' => 'calendar']);
    $service = monthlyService($subscription, '2022-03-01 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2022-02-28 23:59:59');
});

it('returns nil to_datetime when the subscription is not started (calendar)', function (): void {
    $subscription = monthlySub(['billing_time' => 'calendar', 'started_at' => null]);
    $service = monthlyService($subscription, '2022-03-01 00:00:00');

    expect($service->toDatetime())->toBeNull();
});

it('takes the customer timezone into account on to_datetime (calendar)', function (): void {
    $subscription = monthlySub(['billing_time' => 'calendar', 'timezone' => 'America/New_York']);
    $service = monthlyService($subscription, '2022-03-01 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2022-02-01 04:59:59');
});

it('computes to_datetime as the end of the current month when pay in advance (calendar)', function (): void {
    $subscription = monthlySub(['billing_time' => 'calendar', 'pay_in_advance' => true]);
    $service = monthlyService($subscription, '2022-03-01 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2022-03-31 23:59:59');
});

it('returns the termination date for a subscription terminated in the period (calendar)', function (): void {
    $subscription = monthlySub(['billing_time' => 'calendar']);
    datesTerminate($subscription, '2022-03-09 00:00:00');
    $service = monthlyService($subscription, '2022-03-10 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2022-03-09 00:00:00');
});

it('clamps to_datetime to the start date when a pending downgrade exists (calendar)', function (): void {
    $subscription = monthlySub([
        'billing_time' => 'calendar',
        'subscription_at' => '2024-09-09 14:00:01',
        'started_at' => '2024-09-09 14:00:01',
    ]);
    datesTerminate($subscription, '2024-09-09 16:00:01');

    $downgradedPlan = App\Models\Plan::factory()->create(['interval' => 'monthly', 'amount_cents' => 0]);
    App\Models\Subscription::factory()->pending()->create([
        'external_id' => $subscription->external_id,
        'plan_id' => $downgradedPlan->id,
        'customer_id' => $subscription->customer_id,
        'organization_id' => $subscription->organization_id,
        'subscription_at' => '2024-09-09 14:00:01',
        'billing_time' => 'calendar',
        'previous_subscription_id' => $subscription->id,
    ]);

    $service = monthlyService($subscription, '2022-03-10 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2024-09-09 14:00:01');
});

it('returns the termination date with a customer timezone (calendar)', function (): void {
    $subscription = monthlySub(['billing_time' => 'calendar', 'timezone' => 'America/New_York']);
    datesTerminate($subscription, '2022-03-09 00:00:00');
    $service = monthlyService($subscription, '2022-03-10 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2022-03-09 00:00:00');
});

it('computes to_datetime as the day before the anniversary day', function (): void {
    $subscription = monthlySub();
    $service = monthlyService($subscription, '2022-03-02 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2022-03-01 23:59:59');
});

it('computes to_datetime when billing the last month of the year', function (): void {
    $subscription = monthlySub();
    $service = monthlyService($subscription, '2022-01-04 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2022-01-01 23:59:59');
});

it('returns the last day of the month when the subscription day does not exist in the month', function (): void {
    $subscription = monthlySub([
        'subscription_at' => '2022-01-31 00:00:00',
        'started_at' => '2022-01-31 00:00:00',
    ]);
    $service = monthlyService($subscription, '2022-02-28 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2022-02-27 23:59:59');
});

it('returns the last day of the month when the subscription day is not the last day', function (): void {
    $subscription = monthlySub([
        'subscription_at' => '2022-01-30 00:00:00',
        'started_at' => '2022-01-30 00:00:00',
    ]);
    $service = monthlyService($subscription, '2022-02-28 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2022-02-27 23:59:59');
});

it('returns the last day of the month when the anniversary is the first day of the month', function (): void {
    $subscription = monthlySub([
        'subscription_at' => '2022-01-01 00:00:00',
        'started_at' => '2022-01-01 00:00:00',
    ]);
    $service = monthlyService($subscription, '2022-03-02 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2022-02-28 23:59:59');
});

it('returns the end of the next period when pay in advance (anniversary)', function (): void {
    $subscription = monthlySub(['pay_in_advance' => true]);
    $service = monthlyService($subscription, '2022-03-02 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2022-04-01 23:59:59');
});

it('returns the termination date for a subscription terminated on the anniversary day', function (): void {
    $subscription = monthlySub();
    datesTerminate($subscription, '2022-03-02 00:00:00');
    $service = monthlyService($subscription, '2022-03-10 00:00:00');

    expect(datesUtc($service->toDatetime()))->toBe('2022-03-02 00:00:00');
});

// -- charges_from_datetime ------------------------------------------------------

it('returns from_datetime for charges_from_datetime (calendar)', function (): void {
    $subscription = monthlySub(['billing_time' => 'calendar']);
    $service = monthlyService($subscription, '2022-03-01 00:00:00');

    expect($service->chargesFromDatetime()->equalTo($service->fromDatetime()))->toBeTrue();
});

it('returns nil charges_from_datetime when not started (calendar)', function (): void {
    $subscription = monthlySub(['billing_time' => 'calendar', 'started_at' => null]);
    $service = monthlyService($subscription, '2022-03-01 00:00:00');

    expect($service->chargesFromDatetime())->toBeNull();
});

it('returns from_datetime for charges_from_datetime with a customer timezone (calendar)', function (): void {
    $subscription = monthlySub(['billing_time' => 'calendar', 'timezone' => 'America/New_York']);
    $service = monthlyService($subscription, '2022-03-01 00:00:00');

    expect($service->chargesFromDatetime()->equalTo($service->fromDatetime()))->toBeTrue();
});

it('closes the hole when the customer timezone changed mid-period', function (): void {
    $subscription = monthlySub(['billing_time' => 'calendar', 'timezone' => 'America/New_York']);

    datesPreviousInvoiceSubscription($subscription, [
        'charges_to_datetime' => '2022-01-31 23:59:59',
    ]);

    $subscription->customer->timezone = 'America/Los_Angeles';
    $subscription->customer->save();

    $service = monthlyService($subscription, '2022-03-02 00:00:00');

    expect(datesUtc($service->chargesFromDatetime()))->toBe('2022-02-01 00:00:00');
});

it('computes correct boundaries when the timezone changed and no past invoice exists', function (): void {
    $subscription = monthlySub(['billing_time' => 'calendar', 'timezone' => 'America/New_York']);

    $subscription->customer->timezone = 'America/Los_Angeles';
    $subscription->customer->save();

    $service = monthlyService($subscription, '2022-03-02 00:00:00');

    expect($service->chargesFromDatetime()->equalTo($service->fromDatetime()))->toBeTrue();
});

it('returns the start date when the subscription started in the middle of a period', function (): void {
    $subscription = monthlySub([
        'billing_time' => 'calendar',
        'started_at' => '2022-03-03 00:00:00',
    ]);
    $service = monthlyService($subscription, '2022-03-01 00:00:00');

    expect(datesUtc($service->chargesFromDatetime()))->toBe('2022-03-03 00:00:00');
});

it('returns the start of the previous period when pay in advance (calendar charges)', function (): void {
    $subscription = monthlySub([
        'pay_in_advance' => true,
        'billing_time' => 'calendar',
        'subscription_at' => '2020-02-02 00:00:00',
        'started_at' => '2020-02-02 00:00:00',
    ]);
    $service = monthlyService($subscription, '2022-03-01 00:00:00');

    expect(datesUtc($service->chargesFromDatetime()))->toBe('2022-02-01 00:00:00');
});

it('returns the start of the previous period when pay in advance (anniversary charges)', function (): void {
    $subscription = monthlySub([
        'pay_in_advance' => true,
        'subscription_at' => '2020-02-02 00:00:00',
        'started_at' => '2020-02-02 00:00:00',
    ]);
    $service = monthlyService($subscription, '2022-03-02 00:00:00');

    expect(datesUtc($service->chargesFromDatetime()))->toBe('2022-02-02 00:00:00');
});

// -- charges_to_datetime ---------------------------------------------------------

it('returns to_datetime for charges_to_datetime (calendar)', function (): void {
    $subscription = monthlySub(['billing_time' => 'calendar']);
    $service = monthlyService($subscription, '2022-03-01 00:00:00');

    expect($service->chargesToDatetime()->equalTo($service->toDatetime()))->toBeTrue();
});

it('returns nil charges_to_datetime when not started (calendar)', function (): void {
    $subscription = monthlySub(['billing_time' => 'calendar', 'started_at' => null]);
    $service = monthlyService($subscription, '2022-03-01 00:00:00');

    expect($service->chargesToDatetime())->toBeNull();
});

it('returns the terminated date when terminated in the middle of a period (calendar)', function (): void {
    $subscription = monthlySub(['billing_time' => 'calendar']);
    datesTerminate($subscription, '2022-03-06 12:23:00');
    $service = monthlyService($subscription, '2022-03-07 00:00:00');

    expect(datesUtc($service->chargesToDatetime()))->toBe('2022-03-06 12:23:00');
});

it('returns the end of the previous period for charges_to_datetime when pay in advance', function (): void {
    $subscription = monthlySub(['billing_time' => 'calendar', 'pay_in_advance' => true]);
    $service = monthlyService($subscription, '2022-03-07 00:00:00');

    $expected = CarbonImmutable::parse('2022-03-01 00:00:00', 'UTC')->subDay()->endOfDay();

    expect(datesUtc($service->chargesToDatetime()))->toBe(datesUtc($expected));
});

it('returns to_datetime for charges_to_datetime (anniversary)', function (): void {
    $subscription = monthlySub();
    $service = monthlyService($subscription, '2022-03-02 00:00:00');

    expect($service->chargesToDatetime()->equalTo($service->toDatetime()))->toBeTrue();
});

it('returns the terminated date when terminated in the middle of a period (anniversary)', function (): void {
    $subscription = monthlySub();
    datesTerminate($subscription, '2022-03-01 00:00:00');
    $service = monthlyService($subscription, '2022-03-02 00:00:00');

    expect(datesUtc($service->chargesToDatetime()))->toBe('2022-03-01 00:00:00');
});

// -- fixed charge boundaries ------------------------------------------------------

it('mirrors charges boundaries for fixed charges', function (): void {
    $subscription = monthlySub(['billing_time' => 'calendar']);
    $service = monthlyService($subscription, '2022-03-01 00:00:00');

    expect($service->fixedChargesFromDatetime()->equalTo($service->chargesFromDatetime()))->toBeTrue()
        ->and($service->fixedChargesToDatetime()->equalTo($service->chargesToDatetime()))->toBeTrue();
});

it('returns nil fixed charge boundaries when not started', function (): void {
    $subscription = monthlySub(['billing_time' => 'calendar', 'started_at' => null]);
    $service = monthlyService($subscription, '2022-03-01 00:00:00');

    expect($service->fixedChargesFromDatetime())->toBeNull()
        ->and($service->fixedChargesToDatetime())->toBeNull();
});

// -- next_end_of_period -----------------------------------------------------------

it('returns the last day of the month for next_end_of_period (calendar)', function (): void {
    $subscription = monthlySub(['billing_time' => 'calendar']);
    $service = monthlyService($subscription, '2022-03-07 00:00:00');

    expect(datesUtc($service->nextEndOfPeriod()))->toBe('2022-03-31 23:59:59');
});

it('takes the customer timezone into account on next_end_of_period (calendar)', function (): void {
    $subscription = monthlySub(['billing_time' => 'calendar', 'timezone' => 'America/New_York']);
    $service = monthlyService($subscription, '2022-03-07 00:00:00');

    expect(datesUtc($service->nextEndOfPeriod()))->toBe('2022-04-01 03:59:59');
});

it('returns the end of the billing month for next_end_of_period (anniversary)', function (): void {
    $subscription = monthlySub();
    $service = monthlyService($subscription, '2022-03-07 00:00:00');

    expect(datesUtc($service->nextEndOfPeriod()))->toBe('2022-04-01 23:59:59');
});

it('takes the customer timezone into account on next_end_of_period (anniversary)', function (): void {
    $subscription = monthlySub(['timezone' => 'America/New_York']);
    $service = monthlyService($subscription, '2022-03-07 00:00:00');

    expect(datesUtc($service->nextEndOfPeriod()))->toBe('2022-04-01 03:59:59');
});

it('walks into the next year for next_end_of_period when the billing month is December', function (): void {
    $subscription = monthlySub();
    $service = monthlyService($subscription, '2021-12-07 00:00:00');

    expect(datesUtc($service->nextEndOfPeriod()))->toBe('2022-01-01 23:59:59');
});

it('returns the billing day when it already is the end of the period', function (): void {
    $subscription = monthlySub();
    $service = monthlyService($subscription, '2022-03-01 00:00:00');

    expect(datesUtc($service->nextEndOfPeriod()))->toBe('2022-03-01 23:59:59');
});

// -- previous_beginning_of_period ---------------------------------------------------

it('returns the first day of the previous month (calendar)', function (): void {
    $subscription = monthlySub(['billing_time' => 'calendar']);
    $service = monthlyService($subscription, '2022-03-07 00:00:00');

    expect(datesUtc($service->previousBeginningOfPeriod()))->toBe('2022-02-01 00:00:00');
});

it('takes the customer timezone into account on previous_beginning_of_period (calendar)', function (): void {
    $subscription = monthlySub(['billing_time' => 'calendar', 'timezone' => 'America/New_York']);
    $service = monthlyService($subscription, '2022-03-07 00:00:00');

    expect(datesUtc($service->previousBeginningOfPeriod()))->toBe('2022-02-01 05:00:00');
});

it('uses the current period when asked (calendar)', function (): void {
    $subscription = monthlySub(['billing_time' => 'calendar']);
    $service = monthlyService($subscription, '2022-03-07 00:00:00');

    expect(datesUtc($service->previousBeginningOfPeriod(true)))->toBe('2022-03-01 00:00:00');
});

it('returns the beginning of the previous period (anniversary)', function (): void {
    $subscription = monthlySub();
    $service = monthlyService($subscription, '2022-03-07 00:00:00');

    expect(datesUtc($service->previousBeginningOfPeriod()))->toBe('2022-02-02 00:00:00');
});

it('takes the customer timezone into account on previous_beginning_of_period (anniversary)', function (): void {
    $subscription = monthlySub(['timezone' => 'America/New_York']);
    $service = monthlyService($subscription, '2022-03-07 00:00:00');

    expect(datesUtc($service->previousBeginningOfPeriod()))->toBe('2022-02-01 05:00:00');
});

it('uses the current period when asked (anniversary)', function (): void {
    $subscription = monthlySub();
    $service = monthlyService($subscription, '2022-03-07 00:00:00');

    expect(datesUtc($service->previousBeginningOfPeriod(true)))->toBe('2022-03-02 00:00:00');
});

// -- single_day_price / durations ----------------------------------------------------

it('computes the single day price on a 28-day month (calendar)', function (): void {
    $subscription = monthlySub(['billing_time' => 'calendar']);
    $service = monthlyService($subscription, '2022-03-07 00:00:00');

    expect($service->singleDayPrice())->toEqual(100 / 28);
});

it('computes the single day price with a given plan amount (calendar)', function (): void {
    $subscription = monthlySub(['billing_time' => 'calendar']);
    $service = monthlyService($subscription, '2022-03-07 00:00:00');

    expect($service->singleDayPrice(null, 1000))->toEqual(1000 / 28);
});

it('computes the single day price on a leap-year February (calendar)', function (): void {
    $subscription = monthlySub([
        'billing_time' => 'calendar',
        'subscription_at' => '2019-02-28 00:00:00',
        'started_at' => '2019-02-28 00:00:00',
    ]);
    $service = monthlyService($subscription, '2020-03-01 00:00:00');

    expect($service->singleDayPrice())->toEqual(100 / 29);
});

it('computes the single day price on a 28-day month (anniversary)', function (): void {
    $subscription = monthlySub();
    $service = monthlyService($subscription, '2022-03-07 00:00:00');

    expect($service->singleDayPrice())->toEqual(100 / 28);
});

it('computes the single day price on a leap-year February (anniversary)', function (): void {
    $subscription = monthlySub([
        'subscription_at' => '2019-02-02 00:00:00',
        'started_at' => '2019-02-02 00:00:00',
    ]);
    $service = monthlyService($subscription, '2020-03-08 00:00:00');

    expect($service->singleDayPrice())->toEqual(100 / 29);
});

it('prorates on the whole period when the subscription started mid-period (anniversary)', function (): void {
    $subscription = monthlySub([
        'subscription_at' => '2024-01-10 00:00:00',
        'started_at' => '2024-03-20 00:00:00',
    ]);
    $service = monthlyService($subscription, '2024-04-01 00:00:00');

    expect($service->singleDayPrice(
        CarbonImmutable::parse('2024-03-20 00:00:00', 'UTC')->startOfDay(),
    ))->toEqual(100 / 31);
});

it('returns the month duration (calendar)', function (): void {
    $subscription = monthlySub(['billing_time' => 'calendar']);
    $service = monthlyService($subscription, '2022-03-07 00:00:00');

    expect($service->chargesDurationInDays())->toBe(28)
        ->and($service->fixedChargesDurationInDays())->toBe(28);
});

it('returns the leap February duration (calendar)', function (): void {
    $subscription = monthlySub([
        'billing_time' => 'calendar',
        'subscription_at' => '2019-02-28 00:00:00',
        'started_at' => '2019-02-28 00:00:00',
    ]);
    $service = monthlyService($subscription, '2020-03-01 00:00:00');

    expect($service->chargesDurationInDays())->toBe(29)
        ->and($service->fixedChargesDurationInDays())->toBe(29);
});

it('returns the month duration (anniversary)', function (): void {
    $subscription = monthlySub();
    $service = monthlyService($subscription, '2022-03-07 00:00:00');

    expect($service->chargesDurationInDays())->toBe(28)
        ->and($service->fixedChargesDurationInDays())->toBe(28);
});

it('returns the leap February duration (anniversary)', function (): void {
    $subscription = monthlySub([
        'subscription_at' => '2019-02-02 00:00:00',
        'started_at' => '2019-02-02 00:00:00',
    ]);
    $service = monthlyService($subscription, '2020-03-08 00:00:00');

    expect($service->chargesDurationInDays())->toBe(29)
        ->and($service->fixedChargesDurationInDays())->toBe(29);
});

it('reflects the timezone-shifted invoice hole fix end to end', function (): void {
    // Rails' closing context: February invoice billed in Asia/Tokyo, customer
    // moves to America/Los_Angeles in March; the March invoice must open at the
    // previous invoice's charges_to_datetime + 1s, never leaving a 16h gap.
    $subscription = monthlySub([
        'billing_time' => 'calendar',
        'subscription_at' => '2022-01-01 00:00:00',
        'started_at' => '2022-01-01 00:00:00',
        'timezone' => 'Asia/Tokyo',
    ]);

    datesPreviousInvoiceSubscription(
        $subscription,
        ['charges_to_datetime' => '2022-01-31 14:59:59'], // 2022-01-31 23:59:59 Asia/Tokyo
        'Asia/Tokyo',
    );

    // Billing happens on March 1st in the (new) customer timezone, i.e.
    // 2022-03-01 08:10 UTC — but the customer timezone on the record is still
    // Tokyo here, matching Rails' pre-update state where
    // timezone_has_changed? is false.
    $service = monthlyService($subscription, '2022-03-01 08:10:00');

    expect(datesUtc($service->chargesFromDatetime()))->toBe('2022-01-31 15:00:00')
        ->and(datesUtc($service->chargesToDatetime()))->toBe('2022-02-28 14:59:59');
});
