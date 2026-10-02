<?php

declare(strict_types=1);

require_once __DIR__.'/../../../Concerns/SubscriptionDateTestHelpers.php';

use App\Models\Subscription;
use App\Services\Subscriptions\DatesService;
use App\Services\Subscriptions\Dates\WeeklyService;
use App\Services\Subscriptions\Dates\YearlyService;
use App\Services\Subscriptions\Dates\MonthlyService;
use App\Services\Subscriptions\Dates\QuarterlyService;
use App\Services\Subscriptions\Dates\SemiannualService;

/**
 * Port of spec/services/subscriptions/dates_service_spec.rb (base class).
 */
it('resolves the correct interval service through new_instance', function (string $interval, string $klass) {
    $subscription = datesSubscriptionFor($interval);
    $service = DatesService::newInstance($subscription, Carbon\CarbonImmutable::parse('2022-03-07 04:20:46', 'UTC'));

    expect($service)->toBeInstanceOf($klass);
})->with([
    ['weekly', WeeklyService::class],
    ['monthly', MonthlyService::class],
    ['quarterly', QuarterlyService::class],
    ['yearly', YearlyService::class],
    ['semiannual', SemiannualService::class],
]);

it('raises NotImplementedError for an unknown interval', function () {
    $subscription = datesSubscriptionFor('monthly');
    $plan = clone $subscription->plan;
    $plan->interval = 99; // unsaved — mirrors allow(plan).to receive(:interval) { :foo }
    $subscription->setRelation('plan', $plan);

    DatesService::newInstance($subscription, Carbon\CarbonImmutable::now());
})->throws(LogicException::class);

// -- fixed_charge_pay_in_advance_interval (anniversary, subscription_at 02 Feb 2021) --

it('computes the fixed charge pay in advance interval (monthly)', function () {
    $subscription = datesSubscriptionFor('monthly', ['billing_time' => 'anniversary']);
    $timestamp = Carbon\CarbonImmutable::parse('2022-03-07 04:20:46', 'UTC')->getTimestamp();

    $result = DatesService::fixedChargePayInAdvanceInterval($timestamp, $subscription);

    expect(datesUtc($result['fixed_charges_from_datetime']))->toBe('2022-03-02 00:00:00')
        ->and(datesUtc($result['fixed_charges_to_datetime']))->toBe('2022-04-01 23:59:59')
        ->and($result['fixed_charges_duration'])->toBe(31);
});

it('computes the fixed charge pay in advance interval (yearly)', function () {
    $subscription = datesSubscriptionFor('yearly', ['billing_time' => 'anniversary']);
    $timestamp = Carbon\CarbonImmutable::parse('2022-03-07 04:20:46', 'UTC')->getTimestamp();

    $result = DatesService::fixedChargePayInAdvanceInterval($timestamp, $subscription);

    expect(datesUtc($result['fixed_charges_from_datetime']))->toBe('2022-02-02 00:00:00')
        ->and(datesUtc($result['fixed_charges_to_datetime']))->toBe('2023-02-01 23:59:59')
        ->and($result['fixed_charges_duration'])->toBe(365);
});

it('computes the fixed charge pay in advance interval (semiannual)', function () {
    $subscription = datesSubscriptionFor('semiannual', ['billing_time' => 'anniversary']);
    $timestamp = Carbon\CarbonImmutable::parse('2022-03-07 04:20:46', 'UTC')->getTimestamp();

    $result = DatesService::fixedChargePayInAdvanceInterval($timestamp, $subscription);

    expect(datesUtc($result['fixed_charges_from_datetime']))->toBe('2022-02-02 00:00:00')
        ->and(datesUtc($result['fixed_charges_to_datetime']))->toBe('2022-08-01 23:59:59')
        ->and($result['fixed_charges_duration'])->toBe(181);
});

it('computes the fixed charge pay in advance interval (quarterly)', function () {
    $subscription = datesSubscriptionFor('quarterly', ['billing_time' => 'anniversary']);
    $timestamp = Carbon\CarbonImmutable::parse('2022-03-07 04:20:46', 'UTC')->getTimestamp();

    $result = DatesService::fixedChargePayInAdvanceInterval($timestamp, $subscription);

    expect(datesUtc($result['fixed_charges_from_datetime']))->toBe('2022-02-02 00:00:00')
        ->and(datesUtc($result['fixed_charges_to_datetime']))->toBe('2022-05-01 23:59:59')
        ->and($result['fixed_charges_duration'])->toBe(89);
});

it('computes the fixed charge pay in advance interval (weekly)', function () {
    $subscription = datesSubscriptionFor('weekly', ['billing_time' => 'anniversary']);
    $timestamp = Carbon\CarbonImmutable::parse('2022-03-07 04:20:46', 'UTC')->getTimestamp();

    $result = DatesService::fixedChargePayInAdvanceInterval($timestamp, $subscription);

    // 2022-03-01 is a Tuesday, the anniversary weekday.
    expect(datesUtc($result['fixed_charges_from_datetime']))->toBe('2022-03-01 00:00:00')
        ->and(datesUtc($result['fixed_charges_to_datetime']))->toBe('2022-03-07 23:59:59')
        ->and($result['fixed_charges_duration'])->toBe(7);
});

// -- terminated_at? (Terminatable concern) ------------------------------------------

it('answers terminated_at? only when the termination covers the timestamp', function () {
    $subscription = datesSubscriptionFor('monthly');
    datesTerminate($subscription, '2022-03-09 12:00:00');

    expect($subscription->terminatedAt(Carbon\CarbonImmutable::parse('2022-03-09 12:00:00', 'UTC')))->toBeTrue()
        ->and($subscription->terminatedAt(Carbon\CarbonImmutable::parse('2022-03-09 11:59:59', 'UTC')))->toBeFalse()
        ->and($subscription->terminatedAt(Carbon\CarbonImmutable::parse('2022-03-10 00:00:00', 'UTC')))->toBeTrue()
        ->and($subscription->terminatedAt(1646827200))->toBeTrue(); // 2022-03-09 12:00 UTC
});

it('returns false from terminated_at? for a non-terminated subscription', function () {
    $subscription = datesSubscriptionFor('monthly');

    expect($subscription->terminatedAt(Carbon\CarbonImmutable::now()))->toBeFalse();
});

// -- model domain helpers ------------------------------------------------------------

it('walks the external_id chain for initial_started_at', function () {
    $first = datesSubscriptionFor('monthly', ['started_at' => '2023-01-01 00:00:00']);
    $second = datesSubscriptionFor('monthly', [
        'external_id' => $first->external_id,
        'started_at' => '2023-06-01 00:00:00',
        'subscription_at' => '2023-06-01 00:00:00',
    ]);

    expect($second->initialStartedAt()->format('Y-m-d'))->toBe('2023-01-01')
        ->and($first->initialStartedAt()->format('Y-m-d'))->toBe('2023-01-01');
});

it('identifies next subscription ignoring canceled ones', function () {
    $current = datesSubscriptionFor('monthly');
    $pending = Subscription::factory()->pending()->create([
        'external_id' => $current->external_id,
        'customer_id' => $current->customer_id,
        'organization_id' => $current->organization_id,
        'previous_subscription_id' => $current->id,
    ]);

    expect($current->nextSubscription()?->id)->toBe($pending->id);

    $pending->markAsCanceled();
    $pending->save();

    expect($current->nextSubscription())->toBeNull();
});

it('marks state transitions with ||= semantics', function () {
    $subscription = datesSubscriptionFor('monthly', ['status' => 'pending', 'started_at' => null, 'activated_at' => null]);
    $subscription->markAsActive('2024-05-01 10:00:00');
    $subscription->save();

    expect($subscription->active())->toBeTrue()
        ->and($subscription->started_at->format('Y-m-d H:i:s'))->toBe('2024-05-01 10:00:00')
        ->and($subscription->activated_at->format('Y-m-d H:i:s'))->toBe('2024-05-01 10:00:00');

    $subscription->markAsTerminated('2024-06-01 10:00:00');
    $subscription->save();
    $firstTerminatedAt = $subscription->terminated_at->format('Y-m-d H:i:s');

    // Rails: mark_as_terminated! keeps the first timestamp (||=).
    $subscription->markAsTerminated('2024-07-01 10:00:00');
    $subscription->save();

    expect($subscription->terminated())->toBeTrue()
        ->and($subscription->terminated_at->format('Y-m-d H:i:s'))->toBe($firstTerminatedAt);
});
