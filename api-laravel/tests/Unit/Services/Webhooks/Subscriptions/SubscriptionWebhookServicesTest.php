<?php

declare(strict_types=1);

use App\Models\Webhook;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Queue;
use App\Services\Webhooks\Subscriptions\StartedService;
use App\Services\Webhooks\Subscriptions\UpdatedService;
use App\Services\Webhooks\Subscriptions\CanceledService;
use App\Services\Webhooks\Subscriptions\TerminatedService;

/**
 * Port of the "creates webhook" shared example for the subscription builders
 * (spec/services/webhooks/subscriptions/*_service_spec.rb).
 */
beforeEach(function (): void {
    Queue::fake();

    $this->organization = Organization::factory()->withoutWebhookEndpoint()->create();
    $this->endpoint = WebhookEndpoint::factory()->forOrganization($this->organization)->create();
    $this->subscription = Subscription::factory()->for($this->organization)->create();
});

it('creates a subscription.started webhook', function (): void {
    StartedService::call(object: $this->subscription);

    $webhook = Webhook::query()->latest('created_at')->first();

    expect($webhook->payload['webhook_type'])->toBe('subscription.started')
        ->and($webhook->payload['object_type'])->toBe('subscription')
        ->and($webhook->payload['organization_id'])->toBe($this->organization->id)
        ->and($webhook->payload['subscription']['lago_id'])->toBe($this->subscription->id)
        ->and($webhook->payload['subscription']['plan_code'])->toBe($this->subscription->plan->code)
        ->and($webhook->object_id)->toBe($this->subscription->id)
        ->and($webhook->object_type)->toBe(Subscription::class);

    Queue::assertPushed(App\Jobs\SendHttpWebhookJob::class, 1);
});

it('creates a subscription.updated webhook', function (): void {
    UpdatedService::call(object: $this->subscription);

    $payload = Webhook::query()->latest('created_at')->first()->payload;

    expect($payload['webhook_type'])->toBe('subscription.updated')
        ->and($payload['object_type'])->toBe('subscription')
        ->and($payload['subscription']['lago_id'])->toBe($this->subscription->id);
});

it('creates a subscription.terminated webhook', function (): void {
    TerminatedService::call(object: $this->subscription);

    $payload = Webhook::query()->latest('created_at')->first()->payload;

    expect($payload['webhook_type'])->toBe('subscription.terminated')
        ->and($payload['object_type'])->toBe('subscription')
        ->and($payload['subscription']['lago_id'])->toBe($this->subscription->id);
});

it('creates a subscription.canceled webhook', function (): void {
    CanceledService::call(object: $this->subscription);

    $payload = Webhook::query()->latest('created_at')->first()->payload;

    expect($payload['webhook_type'])->toBe('subscription.canceled')
        ->and($payload['object_type'])->toBe('subscription')
        ->and($payload['subscription']['lago_id'])->toBe($this->subscription->id);
});
