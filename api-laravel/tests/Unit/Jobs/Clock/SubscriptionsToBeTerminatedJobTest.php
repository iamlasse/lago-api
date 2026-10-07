<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Jobs\SendWebhookJob;
use App\Models\Subscription;
use Illuminate\Support\Facades\Queue;
use App\Jobs\Clock\SubscriptionsToBeTerminatedJob;

uses()->group('ledger:job:Clock.SubscriptionsToBeTerminatedJob');

/**
 * Port of Rails' spec/jobs/clock/subscriptions_to_be_terminated_job_spec.rb —
 * the hourly termination alert fans out one subscription.termination_alert
 * webhook per active subscription ending in 15 or 45 days, once per day.
 */
function alertSubscription(?string $endingAt, array $subscriptionAttributes = []): Subscription
{
    return Subscription::factory()
        ->forCustomer(Customer::factory()->create())
        ->create(array_filter([
            'ending_at' => $endingAt === null ? null : Illuminate\Support\Facades\Date::parse($endingAt),
        ]) + $subscriptionAttributes);
}

beforeEach(function (): void {
    Queue::fake();
    Illuminate\Support\Facades\Date::setTestNow(Illuminate\Support\Facades\Date::parse('2026-06-01 12:00:00'));
});

afterEach(fn () => Illuminate\Support\Facades\Date::setTestNow());

it('enqueues a termination alert for subscriptions ending in 15 days', function (): void {
    $subscription = alertSubscription('2026-06-16 00:00:00');
    alertSubscription('2027-06-16 00:00:00');

    (new SubscriptionsToBeTerminatedJob)->handle();

    Queue::assertPushed(SendWebhookJob::class, 1);

    Queue::assertPushed(
        SendWebhookJob::class,
        fn (SendWebhookJob $job) => $job->webhookType === 'subscription.termination_alert'
            && $job->object instanceof Subscription
            && $job->object->id === $subscription->id,
    );
});

it('enqueues a termination alert for subscriptions ending in 45 days', function (): void {
    alertSubscription('2026-07-16 00:00:00');

    (new SubscriptionsToBeTerminatedJob)->handle();

    Queue::assertPushed(SendWebhookJob::class, 1);
});

it('does not enqueue for subscriptions without a matching ending_at', function (): void {
    alertSubscription(null);
    alertSubscription('2026-06-11 00:00:00'); // 10 days out

    (new SubscriptionsToBeTerminatedJob)->handle();

    Queue::assertNotPushed(SendWebhookJob::class);
});

it('does not enqueue for non-active subscriptions', function (): void {
    alertSubscription('2026-06-16 00:00:00', ['status' => 0]); // pending
    alertSubscription('2026-06-16 00:00:00', ['status' => 2]); // terminated

    (new SubscriptionsToBeTerminatedJob)->handle();

    Queue::assertNotPushed(SendWebhookJob::class);
});

it('does not re-alert a subscription alerted today', function (): void {
    $subscription = alertSubscription('2026-06-16 00:00:00');

    // Rails: a subscription.termination_alert webhook created today for the
    // subscription suppresses the second alert.
    App\Models\Webhook::factory()->create([
        'organization_id' => $subscription->organization_id,
        'webhook_type' => 'subscription.termination_alert',
        'object_type' => 'Subscription',
        'object_id' => $subscription->id,
    ]);

    (new SubscriptionsToBeTerminatedJob)->handle();

    Queue::assertNotPushed(SendWebhookJob::class);
});
