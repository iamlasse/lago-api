<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\Customer;
use App\Models\DailyUsage;
use Carbon\CarbonImmutable;
use App\Models\Subscription;
use App\Models\BillableMetric;
use App\Jobs\DailyUsages\ComputeJob;
use Illuminate\Support\Facades\Queue;
use Database\Factories\BillableMetricFactory;
use App\Services\DailyUsages\ComputeAllService;

uses()->group('ledger:svc:DailyUsages.ComputeAllService');

/**
 * Port of Rails' spec/services/daily_usages/compute_all_service_spec.rb
 * (the legs and gating scenarios).
 */
function casTimestamp(): CarbonImmutable
{
    return CarbonImmutable::parse('2024-10-22 00:05:00', 'UTC');
}

function casSetup(array $customerAttributes = [], array $subscriptionAttributes = [], array $organizationAttributes = []): array
{
    $organization = App\Models\Organization::factory()->create($organizationAttributes + [
        'premium_integrations' => ['revenue_analytics'],
    ]);
    $customer = Customer::factory()->for($organization)->create($customerAttributes);
    $subscriptions = Subscription::factory()->for($customer)->count(5)->create($subscriptionAttributes + [
        'organization_id' => $organization->id,
        'last_received_event_on' => casTimestamp()->subDay()->toDateString(),
    ]);

    return [$organization, $customer, $subscriptions];
}

function casPlan(App\Models\Organization $organization): Plan
{
    return Plan::factory()->for($organization)->create();
}

beforeEach(function (): void {
    Queue::fake();
    config(['lago.license' => 'premium-license-token']);
});

afterEach(function (): void {
    config(['lago.license' => null]);
});

it('enqueues one compute job per subscription', function (): void {
    [, , $subscriptions] = casSetup();

    $result = ComputeAllService::call(timestamp: casTimestamp());

    expect($result->success())->toBeTrue();

    Queue::assertPushed(ComputeJob::class, 5);
    Queue::assertPushed(ComputeJob::class, fn (ComputeJob $job) => $subscriptions->contains($job->subscription));
});

it('skips subscriptions already computed for yesterday', function (): void {
    [, $customer, $subscriptions] = casSetup();

    DailyUsage::factory()->forSubscription($subscriptions[0])->forCustomer($customer)->create([
        'usage_date' => casTimestamp()->subDay()->toDateString(),
    ]);

    ComputeAllService::call(timestamp: casTimestamp());

    Queue::assertPushed(ComputeJob::class, 4);
});

it('does not enqueue when last_received_event_on is nil', function (): void {
    casSetup([], ['last_received_event_on' => null]);

    ComputeAllService::call(timestamp: casTimestamp());

    Queue::assertNothingPushed();
});

it('enqueues when last_received_event_on is today', function (): void {
    casSetup([], ['last_received_event_on' => casTimestamp()->toDateString()]);

    ComputeAllService::call(timestamp: casTimestamp());

    Queue::assertPushed(ComputeJob::class, 5);
});

it('does not enqueue when last_received_event_on is stale', function (): void {
    casSetup([], ['last_received_event_on' => casTimestamp()->subDays(5)->toDateString()]);

    ComputeAllService::call(timestamp: casTimestamp());

    Queue::assertNothingPushed();
});

it('enqueues time-dependent subscriptions even without recent events', function (): void {
    $organization = App\Models\Organization::factory()->create(['premium_integrations' => ['revenue_analytics']]);
    $customer = Customer::factory()->for($organization)->create();
    $plan = casPlan($organization);
    $metric = BillableMetric::factory()->create(['organization_id' => $organization->id]);

    // Prorated charge.
    App\Models\Charge::factory()->standard()->create([
        'properties' => ['amount' => '100'],
        'prorated' => true,
        'organization_id' => $organization->id,
        'billable_metric_id' => $metric->id,
        'plan_id' => $plan->id,
    ]);

    Subscription::factory()->for($customer)->count(3)->create([
        'organization_id' => $organization->id,
        'plan_id' => $plan->id,
        'last_received_event_on' => casTimestamp()->subDays(5)->toDateString(),
    ]);

    ComputeAllService::call(timestamp: casTimestamp());

    Queue::assertPushed(ComputeJob::class, 3);
});

it('enqueues weighted-sum subscriptions even without recent events', function (): void {
    $organization = App\Models\Organization::factory()->create(['premium_integrations' => ['revenue_analytics']]);
    $customer = Customer::factory()->for($organization)->create();
    $plan = casPlan($organization);
    $metric = BillableMetric::factory()->create([
        'organization_id' => $organization->id,
        'aggregation_type' => BillableMetricFactory::WEIGHTED_SUM_AGG,
    ]);

    App\Models\Charge::factory()->standard(['amount' => '100'])->create([
        'organization_id' => $organization->id,
        'billable_metric_id' => $metric->id,
        'plan_id' => $plan->id,
    ]);

    Subscription::factory()->for($customer)->count(2)->create([
        'organization_id' => $organization->id,
        'plan_id' => $plan->id,
        'last_received_event_on' => casTimestamp()->subDays(5)->toDateString(),
    ]);

    ComputeAllService::call(timestamp: casTimestamp());

    Queue::assertPushed(ComputeJob::class, 2);
});

it('does not enqueue when the only charge is not time-dependent', function (): void {
    $organization = App\Models\Organization::factory()->create(['premium_integrations' => ['revenue_analytics']]);
    $customer = Customer::factory()->for($organization)->create();
    $plan = casPlan($organization);
    $metric = BillableMetric::factory()->create(['organization_id' => $organization->id, 'recurring' => false]);

    App\Models\Charge::factory()->standard(['amount' => '100'])->create([
        'organization_id' => $organization->id,
        'billable_metric_id' => $metric->id,
        'plan_id' => $plan->id,
    ]);

    Subscription::factory()->for($customer)->count(2)->create([
        'organization_id' => $organization->id,
        'plan_id' => $plan->id,
        'last_received_event_on' => casTimestamp()->subDays(5)->toDateString(),
    ]);

    ComputeAllService::call(timestamp: casTimestamp());

    Queue::assertNothingPushed();
});

it('does not enqueue when the charge is deleted', function (): void {
    $organization = App\Models\Organization::factory()->create(['premium_integrations' => ['revenue_analytics']]);
    $customer = Customer::factory()->for($organization)->create();
    $plan = casPlan($organization);
    $metric = BillableMetric::factory()->create(['organization_id' => $organization->id]);

    App\Models\Charge::factory()->standard()->create([
        'properties' => ['amount' => '100'],
        'prorated' => true,
        'organization_id' => $organization->id,
        'billable_metric_id' => $metric->id,
        'plan_id' => $plan->id,
        'deleted_at' => casTimestamp(),
    ]);

    Subscription::factory()->for($customer)->count(2)->create([
        'organization_id' => $organization->id,
        'plan_id' => $plan->id,
        'last_received_event_on' => casTimestamp()->subDays(5)->toDateString(),
    ]);

    ComputeAllService::call(timestamp: casTimestamp());

    Queue::assertNothingPushed();
});

it('takes the timezone into account', function (): void {
    // Sao Paulo customers only enter the new day at 03:00+ UTC.
    [, $customer] = casSetup(
        customerAttributes: ['timezone' => 'America/Sao_Paulo'],
    );

    $customer->billingEntity->update(['timezone' => 'America/Sao_Paulo']);

    ComputeAllService::call(timestamp: casTimestamp());

    Queue::assertNothingPushed();
});

it('enqueues sao paulo customers when their day starts', function (): void {
    [, $customer] = casSetup(
        customerAttributes: ['timezone' => 'America/Sao_Paulo'],
    );

    $customer->billingEntity->update(['timezone' => 'America/Sao_Paulo']);

    ComputeAllService::call(timestamp: CarbonImmutable::parse('2024-10-22 03:05:00', 'UTC'));

    Queue::assertPushed(ComputeJob::class, 5);
});

it('does not enqueue when skip_daily_usage is true', function (): void {
    casSetup([], ['skip_daily_usage' => true, 'last_received_event_on' => casTimestamp()->toDateString()]);

    ComputeAllService::call(timestamp: casTimestamp());

    Queue::assertNothingPushed();
});

it('does not enqueue without the revenue_analytics premium integration', function (): void {
    casSetup(organizationAttributes: ['premium_integrations' => []]);

    ComputeAllService::call(timestamp: casTimestamp());

    Queue::assertNothingPushed();
});

it('does not enqueue without a premium license', function (): void {
    config(['lago.license' => null]);

    casSetup();

    ComputeAllService::call(timestamp: casTimestamp());

    Queue::assertNothingPushed();
});

it('delays the jobs by at most the configured jitter', function (): void {
    $_ENV['LAGO_DAILY_USAGE_SCHEDULING_JITTER_SECONDS'] = '60';
    $_SERVER['LAGO_DAILY_USAGE_SCHEDULING_JITTER_SECONDS'] = '60';

    try {
        [, , $subscriptions] = casSetup();

        ComputeAllService::call(timestamp: casTimestamp());

        Queue::assertPushed(ComputeJob::class, function (ComputeJob $job) use ($subscriptions): bool {
            return $subscriptions->contains($job->subscription)
                && $job->delay !== null
                && (int) $job->delay <= 60;
        });
    } finally {
        unset($_ENV['LAGO_DAILY_USAGE_SCHEDULING_JITTER_SECONDS'], $_SERVER['LAGO_DAILY_USAGE_SCHEDULING_JITTER_SECONDS']);
    }
});
