<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Subscription;
use Illuminate\Support\Facades\Queue;
use App\Jobs\Clock\TerminateEndedSubscriptionsJob;
use App\Jobs\Subscriptions\TerminateEndedSubscriptionJob;

uses()->group('ledger:job:Clock.TerminateEndedSubscriptionsJob');

/**
 * Port of Rails' spec/jobs/clock/terminate_ended_subscriptions_job_spec.rb —
 * the hourly sweep enqueues one TerminateEndedSubscriptionJob per active
 * subscription whose ending_at day (in the customer's timezone) is today.
 */
function clockEndingSubscription(?string $endingAt, array $customerAttributes = []): Subscription
{
    $customer = Customer::factory()->create($customerAttributes);

    return Subscription::factory()->forCustomer($customer)->create([
        'ending_at' => $endingAt === null ? null : Illuminate\Support\Facades\Date::parse($endingAt),
    ]);
}

it('enqueues a terminate job for subscriptions whose ending_at day is today', function (): void {
    Queue::fake();

    Illuminate\Support\Facades\Date::setTestNow(Illuminate\Support\Facades\Date::parse('2023-02-15 12:00:00'));

    $endingToday = clockEndingSubscription('2023-02-15 06:00:00');
    $endingLater = clockEndingSubscription('2024-02-15 06:00:00');
    $noEnding = clockEndingSubscription(null);

    (new TerminateEndedSubscriptionsJob)->handle();

    Queue::assertPushed(TerminateEndedSubscriptionJob::class, 1);

    Queue::assertPushed(
        TerminateEndedSubscriptionJob::class,
        fn (TerminateEndedSubscriptionJob $job) => $job->subscription->id === $endingToday->id,
    );

    Illuminate\Support\Facades\Date::setTestNow();
});

it('does not enqueue subscriptions ending on nearby days', function (): void {
    Queue::fake();

    Illuminate\Support\Facades\Date::setTestNow(Illuminate\Support\Facades\Date::parse('2023-02-15 12:00:00'));

    clockEndingSubscription('2023-02-14 23:00:00');
    clockEndingSubscription('2023-02-16 01:00:00');

    (new TerminateEndedSubscriptionsJob)->handle();

    Queue::assertNotPushed(TerminateEndedSubscriptionJob::class);

    Illuminate\Support\Facades\Date::setTestNow();
});

it('takes the customer timezone into account (far behind UTC)', function (): void {
    Queue::fake();

    // now = 2022-10-20 12:00 UTC = 2022-10-20 01:00 in Midway (-11);
    // ending_at = 2022-10-21 00:30 UTC = 2022-10-20 13:30 in Midway — the
    // same local day (Oct 20), so the subscription terminates even though
    // the UTC days differ.
    Illuminate\Support\Facades\Date::setTestNow(Illuminate\Support\Facades\Date::parse('2022-10-20 12:00:00'));

    $subscription = clockEndingSubscription('2022-10-21 00:30:00', ['timezone' => 'Pacific/Midway']);

    (new TerminateEndedSubscriptionsJob)->handle();

    Queue::assertPushed(
        TerminateEndedSubscriptionJob::class,
        fn (TerminateEndedSubscriptionJob $job) => $job->subscription->id === $subscription->id,
    );

    Illuminate\Support\Facades\Date::setTestNow();
});
