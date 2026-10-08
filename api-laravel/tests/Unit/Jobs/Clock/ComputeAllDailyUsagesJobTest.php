<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Jobs\DailyUsages\ComputeJob;
use Illuminate\Support\Facades\Queue;
use App\Jobs\Clock\ComputeAllDailyUsagesJob;
use App\Services\DailyUsages\ComputeAllService;

uses()->group('ledger:job:Clock.ComputeAllDailyUsagesJob');

/**
 * Port of Rails' spec/jobs/clock/compute_all_daily_usages_job_spec.rb —
 * the hourly entry runs DailyUsages::ComputeAllService with the current
 * time (the per-organization fan-out is covered by the service spec).
 */
it('runs the compute all service and enqueues compute jobs', function (): void {
    Queue::fake();
    config(['lago.license' => 'premium-license-token']);

    $organization = App\Models\Organization::factory()->create(['premium_integrations' => ['revenue_analytics']]);
    $customer = Customer::factory()->for($organization)->create();
    App\Models\Subscription::factory()->for($customer)->count(2)->create([
        'organization_id' => $organization->id,
        'last_received_event_on' => now()->utc()->toDateString(),
    ]);

    $this->travelTo(now()->utc()->startOfDay()->addDay()->addMinutes(5));

    (new ComputeAllDailyUsagesJob)->handle();

    Queue::assertPushed(ComputeJob::class, 2);

    config(['lago.license' => null]);
});
