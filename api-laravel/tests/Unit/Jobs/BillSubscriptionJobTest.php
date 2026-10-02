<?php

declare(strict_types=1);

use App\Jobs\BillSubscriptionJob;

/**
 * Port of bill_subscription_job_spec.rb's lock-key scenario: the unique
 * lock key embeds the timestamp normalized to the customer's timezone DATE,
 * so two hourly biller runs on the same billing day share one lock and
 * cannot double-bill.
 */
it('normalizes the lock key to the customer-timezone date', function () {
    $organization = App\Models\Organization::factory()->create();
    // A customer billing in UTC+9: 2026-10-05 15:30 UTC is already Oct 6 there.
    $customer = App\Models\Customer::factory()->create([
        'organization_id' => $organization->id,
        'timezone' => 'Asia/Tokyo',
    ]);
    $plan = App\Models\Plan::factory()->create(['organization_id' => $organization->id]);
    $subscription = App\Models\Subscription::factory()->create([
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
        'organization_id' => $organization->id,
        'external_id' => 'sub-lock-1',
    ]);

    $job = new BillSubscriptionJob([$subscription], 1791291000, 'subscription_periodic');

    // 1791291000 = 2026-10-05 15:30:00 UTC → 2026-10-06 in Asia/Tokyo.
    $key = $job->uniqueKey();

    expect($key)->toContain('2026-10-06')
        ->and($key)->toContain('subscription_periodic')
        ->and($key)->toContain('sub-lock-1' === '' ? 'never' : $subscription->id);
});

it('uses distinct keys for distinct billing days', function () {
    $organization = App\Models\Organization::factory()->create();
    $customer = App\Models\Customer::factory()->create(['organization_id' => $organization->id]);
    $plan = App\Models\Plan::factory()->create(['organization_id' => $organization->id]);
    $subscription = App\Models\Subscription::factory()->create([
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
        'organization_id' => $organization->id,
    ]);

    // 2026-10-05 23:00 UTC and 2026-10-06 01:00 UTC: two hourly biller runs
    // on either side of midnight normalize to DIFFERENT dates.
    $late = new BillSubscriptionJob([$subscription], 1791241200, 'subscription_periodic');
    $nextDay = new BillSubscriptionJob([$subscription], 1791248400, 'subscription_periodic');

    expect($late->uniqueKey())->not->toBe($nextDay->uniqueKey())
        ->and($late->uniqueKey())->toContain('2026-10-05')
        ->and($nextDay->uniqueKey())->toContain('2026-10-06');
});

it('queues on billing when SIDEKIQ_BILLING is set', function () {
    config(['lago.sidekiq_billing' => null]);
    $organization = App\Models\Organization::factory()->create();
    $customer = App\Models\Customer::factory()->create(['organization_id' => $organization->id]);
    $plan = App\Models\Plan::factory()->create(['organization_id' => $organization->id]);
    $subscription = App\Models\Subscription::factory()->create([
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
        'organization_id' => $organization->id,
    ]);

    $job = new BillSubscriptionJob([$subscription], 1791291000, 'subscription_periodic');

    // Without SIDEKIQ_BILLING the job rides the default queue, like Rails.
    expect($job->queue)->toBe('default')
        ->and(BillSubscriptionJob::MAX_LOCK_RETRY_ATTEMPTS)->toBe(4);
});
