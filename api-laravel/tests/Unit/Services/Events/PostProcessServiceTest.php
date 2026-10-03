<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Organization;
use App\Models\Subscription;
use App\Services\Events\PostProcessService;

uses()->group('ledger:svc:Events.PostProcessService');

/**
 * Unit contract of the post-ingestion resolution (the Rails spec exercises
 * PostProcessService through PostProcessJob behavior; the consumers of the
 * resolution are TODO(port) — see the service docblock).
 */
function postProcessSubscription(Organization $organization, array $attributes = []): Subscription
{
    return Subscription::factory()->create(array_merge([
        'organization_id' => $organization->id,
        'external_id' => 'sub_pp',
        'started_at' => now()->subDays(2),
    ], $attributes));
}

function postProcessEvent(Organization $organization, string $timestamp): Event
{
    $event = new Event();
    $event->organization_id = $organization->id;
    $event->code = 'bm_code';
    $event->external_subscription_id = 'sub_pp';
    $event->timestamp = Illuminate\Support\Facades\Date::parse($timestamp);
    $event->properties = [];

    return $event;
}

it('executes successfully and returns the event', function (): void {
    $organization = Organization::factory()->create();
    $event = postProcessEvent($organization, now()->subDay()->toDateTimeString());

    $result = PostProcessService::call(event: $event);

    expect($result->success())->toBeTrue()
        ->and($result->event)->toBe($event);
});

it('resolves the subscription matching the event timestamp window', function (): void {
    $organization = Organization::factory()->create();
    $subscription = postProcessSubscription($organization);

    $service = new PostProcessService(postProcessEvent($organization, now()->subDay()->toDateTimeString()));

    expect($service->subscriptions()->pluck('id'))->toContain($subscription->id)
        ->and($service->activeSubscription()?->id)->toBe($subscription->id)
        ->and($service->customer()?->id)->toBe($subscription->customer_id);
});

it('ignores subscriptions that start after the event', function (): void {
    $organization = Organization::factory()->create();
    postProcessSubscription($organization, ['started_at' => now()->addDay()]);

    $service = new PostProcessService(postProcessEvent($organization, now()->toDateTimeString()));

    expect($service->subscriptions())->toBeEmpty()
        ->and($service->fallbackSubscription())->toBeNull();
});

it('ignores terminated subscriptions that ended before the event', function (): void {
    $organization = Organization::factory()->create();
    postProcessSubscription($organization, ['terminated_at' => now()->subDays(2)]);

    $service = new PostProcessService(postProcessEvent($organization, now()->toDateTimeString()));

    expect($service->subscriptions())->toBeEmpty();
});

it('ignores incomplete subscriptions', function (): void {
    $organization = Organization::factory()->create();
    $subscription = postProcessSubscription($organization);
    $subscription->update(['status' => App\Enums\SubscriptionStatus::Incomplete->value]);

    $service = new PostProcessService(postProcessEvent($organization, now()->toDateTimeString()));

    expect($service->subscriptions())->toBeEmpty();
});

it('falls back to the active subscription for a backdated recurring event', function (): void {
    $organization = Organization::factory()->create();
    $subscription = postProcessSubscription($organization);
    $organization->billableMetrics()->create([
        'name' => 'recurring',
        'code' => 'bm_code',
        'aggregation_type' => App\Enums\AggregationType::CountAgg->value,
        'recurring' => true,
    ]);

    // The event is older than the subscription's start — no window match.
    $service = new PostProcessService(
        postProcessEvent($organization, now()->subDays(5)->toDateTimeString()),
    );

    expect($service->subscriptions())->toBeEmpty()
        ->and($service->fallbackSubscription()?->id)->toBe($subscription->id);
});

it('does not fall back for a non-recurring metric', function (): void {
    $organization = Organization::factory()->create();
    postProcessSubscription($organization);
    $organization->billableMetrics()->create([
        'name' => 'plain',
        'code' => 'bm_code',
        'aggregation_type' => App\Enums\AggregationType::CountAgg->value,
        'recurring' => false,
    ]);

    $service = new PostProcessService(
        postProcessEvent($organization, now()->subDays(5)->toDateTimeString()),
    );

    expect($service->fallbackSubscription())->toBeNull();
});
