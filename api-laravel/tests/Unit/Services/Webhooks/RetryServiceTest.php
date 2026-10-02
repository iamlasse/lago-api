<?php

declare(strict_types=1);

use App\Models\Webhook;
use App\Models\Organization;
use App\Models\WebhookEndpoint;
use App\Jobs\SendHttpWebhookJob;
use Illuminate\Support\Facades\Queue;
use App\Services\Webhooks\RetryService;

beforeEach(function () {
    Queue::fake();

    $this->endpoint = WebhookEndpoint::factory()->create();
    $this->webhook = Webhook::factory()
        ->for($this->endpoint, 'webhookEndpoint')
        ->for(Organization::findOrFail($this->endpoint->organization_id), 'organization')
        ->failed()
        ->create();
});

it('enqueues an http webhook job', function () {
    RetryService::call(webhook: $this->webhook);

    Queue::assertPushed(SendHttpWebhookJob::class, fn ($job) => $job->webhook->is($this->webhook));
});

it('assigns the webhook to the result', function () {
    $result = RetryService::call(webhook: $this->webhook);

    expect($result->success())->toBeTrue()
        ->and($result->webhook->is($this->webhook))->toBeTrue();
});

it('fails when the webhook is not found', function () {
    $result = RetryService::call(webhook: null);

    expect($result->success())->toBeFalse()
        ->and($result->getError())->toBeInstanceOf(App\Services\Failures\NotFoundFailure::class)
        ->and($result->getError()->resource)->toBe('webhook');
});

it('fails when the webhook already succeeded', function () {
    $this->webhook->update(['status' => 1]); // :succeeded

    $result = RetryService::call(webhook: $this->webhook);

    expect($result->success())->toBeFalse()
        ->and($result->getError()->code)->toBe('is_succeeded');

    Queue::assertNothingPushed();
});
