<?php

declare(strict_types=1);

use App\Jobs\BillSubscriptionJob;
use Illuminate\Support\Facades\Bus;
use App\Jobs\Subscriptions\TerminateJob;
use App\Jobs\Clock\SubscriptionsBillerJob;
use App\Jobs\Subscriptions\OrganizationBillingJob;

/**
 * Port of spec/jobs/clock/subscriptions_biller_job_spec.rb +
 * spec/jobs/subscriptions/organization_billing_job_spec.rb — the hourly
 * billing clock: one OrganizationBillingJob per organization, then the
 * per-customer BillSubscriptionJob dispatches from the UNION query in
 * Organizations\BillingService (grouping itself is covered by
 * tests/Feature/Services/Organizations/BillingServiceTest.php).
 */
function clockBillerFixture(string $externalId): array
{
    $organization = App\Models\Organization::factory()->create();
    $customer = App\Models\Customer::factory()->create(['organization_id' => $organization->id]);
    // Weekly anniversary billing fires on the same weekday as the start —
    // computable for ANY run date (a monthly calendar day would only fire on
    // the 1st, making the test date-dependent).
    $plan = App\Models\Plan::factory()->create([
        'organization_id' => $organization->id,
        'interval' => 'weekly',
    ]);
    $startedAt = now('UTC')->subWeek()->startOfDay();

    $subscription = App\Models\Subscription::factory()->create([
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
        'organization_id' => $organization->id,
        'status' => 'active',
        'billing_time' => 'anniversary',
        'external_id' => $externalId,
        'started_at' => $startedAt,
        'activated_at' => $startedAt,
        'subscription_at' => $startedAt,
    ]);

    return compact('organization', 'customer', 'plan', 'subscription');
}

it('enqueues one OrganizationBillingJob per organization on the clock queue', function (): void {
    $first = clockBillerFixture('sub-clock-1');
    $second = clockBillerFixture('sub-clock-2');

    Bus::fake([OrganizationBillingJob::class]);

    (new SubscriptionsBillerJob)->handle();

    Bus::assertDispatched(OrganizationBillingJob::class, 2);
    Bus::assertDispatched(function (OrganizationBillingJob $job) use ($first): bool {
        return $job->organization->id === $first['organization']->id;
    });
    Bus::assertDispatched(function (OrganizationBillingJob $job) use ($second): bool {
        return $job->organization->id === $second['organization']->id;
    });

    // Port of `unique :until_executed, on_conflict: :log, lock_ttl: 4.hours`.
    expect((new SubscriptionsBillerJob)->uniqueFor())->toBe(4 * 3600)
        ->and((new SubscriptionsBillerJob)->queue)->toBe('clock');
})->group('ledger:job:Clock.SubscriptionsBillerJob');

it('runs the organization biller with the current UTC time', function (): void {
    $f = clockBillerFixture('sub-clock-org-1');

    // Rails: Subscriptions::OrganizationBillingService.call(organization:, billing_at: Time.current).
    $expectedTimestamp = now('UTC')->getTimestamp();

    Bus::fake();
    (new OrganizationBillingJob($f['organization']))->handle();

    Bus::assertDispatched(BillSubscriptionJob::class, 1);
    Bus::assertDispatched(function (BillSubscriptionJob $job) use ($expectedTimestamp): bool {
        return $job->invoicingReason === 'subscription_periodic'
            && $job->timestamp === $expectedTimestamp;
    });
    Bus::assertNotDispatched(TerminateJob::class);

    expect((new OrganizationBillingJob($f['organization']))->uniqueFor())->toBe(12 * 3600);
})->group('ledger:job:Subscriptions.OrganizationBillingJob');

it('bills nothing for an organization without billable subscriptions', function (): void {
    $organization = App\Models\Organization::factory()->create();

    Bus::fake();
    (new OrganizationBillingJob($organization))->handle();

    Bus::assertNotDispatched(BillSubscriptionJob::class);
})->group('ledger:job:Subscriptions.OrganizationBillingJob');
