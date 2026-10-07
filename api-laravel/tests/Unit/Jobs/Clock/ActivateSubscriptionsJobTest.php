<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Subscription;
use Illuminate\Support\Carbon;
use App\Jobs\Clock\ActivateSubscriptionsJob;

uses()->group('ledger:job:Clock.ActivateSubscriptionsJob');

/**
 * Port of Rails' activate-all-pending flow exercised by
 * Clock::ActivateSubscriptionsJob (spec/services/subscriptions/
 * activate_all_pending_service_spec.rb): pending first-generation
 * subscriptions whose subscription_at day (in the customer's timezone) has
 * arrived are activated; downgrade placeholders (previous_subscription set)
 * are left to the biller.
 */
function clockPendingSubscription(array $attributes = [], array $customerAttributes = []): Subscription
{
    $customer = Customer::factory()->create($customerAttributes);

    return Subscription::factory()
        ->forCustomer($customer)
        ->pending()
        ->create($attributes);
}

it('activates pending subscriptions whose subscription_at day is today', function (): void {
    Carbon::setTestNow(Carbon::parse('2023-03-10 11:00:00'));

    $due = clockPendingSubscription(['subscription_at' => Carbon::parse('2023-03-10 08:00:00')]);
    $future = clockPendingSubscription(['subscription_at' => Carbon::parse('2023-03-11 08:00:00')]);

    (new ActivateSubscriptionsJob)->handle();

    expect($due->refresh()->statusValue())->toBe(1) // active
        ->and($future->refresh()->statusValue())->toBe(0); // pending
});

it('leaves downgrade placeholders to the biller', function (): void {
    Carbon::setTestNow(Carbon::parse('2023-03-10 11:00:00'));

    $placeholder = clockPendingSubscription([
        'subscription_at' => Carbon::parse('2023-03-10 08:00:00'),
        'previous_subscription_id' => Subscription::factory()->create()->id,
    ]);

    (new ActivateSubscriptionsJob)->handle();

    expect($placeholder->refresh()->statusValue())->toBe(0); // pending
});

it('takes the customer timezone into account', function (): void {
    // now = 2022-12-31 23:00 UTC = 2023-01-01 12:00 in Auckland (+13); the
    // subscription's subscription_at day (2023-01-01 local) matches now's
    // local day, so it activates even though the UTC dates differ.
    Carbon::setTestNow(Carbon::parse('2022-12-31 23:00:00'));

    $subscription = clockPendingSubscription(
        ['subscription_at' => Carbon::parse('2023-01-01 00:00:00')],
        ['timezone' => 'Pacific/Auckland'],
    );

    (new ActivateSubscriptionsJob)->handle();

    expect($subscription->refresh()->statusValue())->toBe(1); // active
});
