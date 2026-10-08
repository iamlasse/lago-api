<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Organization;
use Illuminate\Support\Facades\Queue;
use App\Jobs\Events\PostValidationJob;
use App\Jobs\Clock\EventsValidationJob;

uses()->group('ledger:job:Clock.EventsValidationJob');

/**
 * Port of Rails' spec/jobs/clock/events_validation_job_spec.rb — the
 * hourly job refreshes the last-hour materialized view and enqueues one
 * Events::PostValidationJob per organization with events and a webhook
 * endpoint.
 */
function validationJobEvent(Organization $organization, array $attributes = []): Event
{
    return Event::factory()->create($attributes + [
        'organization_id' => $organization->id,
        // Inside the MV window ([date_trunc('hour', now()) - 1h,
        // date_trunc('hour', now()))).
        'created_at' => now()->startOfHour()->subMinutes(25),
    ]);
}

beforeEach(function (): void {
    Queue::fake();
});

it('refreshes the events materialized view', function (): void {
    $organization = Organization::factory()->withoutWebhookEndpoint()->create();
    validationJobEvent($organization);

    (new EventsValidationJob)->handle();

    $rows = Illuminate\Support\Facades\DB::select(
        'SELECT count(*) AS c FROM last_hour_events_mv WHERE organization_id = ?',
        [$organization->id]
    );

    expect((int) $rows[0]->c)->toBe(1);
});

it('enqueues a post validation job for organizations with events and endpoints', function (): void {
    $organization = Organization::factory()->create();
    validationJobEvent($organization);

    (new EventsValidationJob)->handle();

    Queue::assertPushed(PostValidationJob::class, fn (PostValidationJob $job) => $job->organization->is($organization));
});

it('does not enqueue a job when the organization has no webhook endpoints', function (): void {
    $organization = Organization::factory()->withoutWebhookEndpoint()->create();
    validationJobEvent($organization);

    (new EventsValidationJob)->handle();

    Queue::assertNotPushed(PostValidationJob::class);
});
