<?php

declare(strict_types=1);

use App\Models\Webhook;
use App\Models\Customer;
use App\Jobs\SendWebhookJob;
use App\Models\Organization;
use App\Models\WebhookEndpoint;
use App\Jobs\SendHttpWebhookJob;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();

    $this->organization = Organization::factory()->withoutWebhookEndpoint()->create();
    $this->endpoint = WebhookEndpoint::factory()->forOrganization($this->organization)->create();
    $this->customer = Customer::factory()->for($this->organization)->create();
});

// -- performLater (Rails: the perform_later override) -----------------------------

it('does not enqueue when the organization has no webhook endpoints', function () {
    $organization = Organization::factory()->withoutWebhookEndpoint()->create();
    $customer = Customer::factory()->for($organization)->create();

    SendWebhookJob::performLater('customer.created', $customer);

    Queue::assertNothingPushed();
});

it('enqueues when the organization has webhook endpoints', function () {
    SendWebhookJob::performLater('customer.created', $this->customer, ['key' => 'value']);

    Queue::assertPushed(SendWebhookJob::class, function (SendWebhookJob $job) {
        return $job->webhookType === 'customer.created'
            && $job->object->is($this->customer)
            && $job->options === ['key' => 'value']
            && $job->webhookId === null;
    });
});

it('enqueues with a webhook id even when endpoints are checked', function () {
    $webhook = Webhook::factory()->for($this->endpoint, 'webhookEndpoint')->create();

    SendWebhookJob::performLater('customer.created', $this->customer, [], $webhook->id);

    Queue::assertPushed(SendWebhookJob::class, fn (SendWebhookJob $job) => $job->webhookId === $webhook->id);
});

// -- queue_for -------------------------------------------------------------------

it('uses the webhook queue by default', function () {
    expect(SendWebhookJob::queueFor('alert.triggered'))->toBe('webhook')
        ->and(SendWebhookJob::queueFor('invoice.created'))->toBe('webhook')
        ->and(SendWebhookJob::queueFor())->toBe('webhook');
});

it('uses the dedicated worker queues when SIDEKIQ_WEBHOOK is true', function () {
    $_ENV['SIDEKIQ_WEBHOOK'] = 'true';
    $_SERVER['SIDEKIQ_WEBHOOK'] = 'true';

    try {
        expect(SendWebhookJob::queueFor('alert.triggered'))->toBe('webhook_worker_high_priority')
            ->and(SendWebhookJob::queueFor('invoice.created'))->toBe('webhook_worker');
    } finally {
        unset($_ENV['SIDEKIQ_WEBHOOK'], $_SERVER['SIDEKIQ_WEBHOOK']);
    }
});

it('runs the job on the queue chosen at construction', function () {
    SendWebhookJob::performLater('customer.created', $this->customer);

    Queue::assertPushed(SendWebhookJob::class, fn ($job) => $job->queue === 'webhook');
});

// -- perform ---------------------------------------------------------------------

it('dispatches the builder service for a registered type', function () {
    $job = new SendWebhookJob('customer.created', $this->customer);
    $job->handle();

    // The customer.created builder fanned out to the endpoint.
    Queue::assertPushed(SendHttpWebhookJob::class, 1);

    $webhook = Webhook::query()->sole();
    expect($webhook->webhook_type)->toBe('customer.created')
        ->and($webhook->payload['customer']['lago_id'])->toBe($this->customer->id);
});

it('raises for an unknown webhook type', function () {
    $job = new SendWebhookJob('totally.unknown', $this->customer);

    expect(fn () => $job->handle())->toThrow(LogicException::class);
});

it('routes legacy webhook_id enqueues straight to the http job', function () {
    $webhook = Webhook::factory()->for($this->endpoint, 'webhookEndpoint')->create();

    (new SendWebhookJob('customer.created', $this->customer, [], $webhook->id))->handle();

    Queue::assertPushed(SendHttpWebhookJob::class, fn ($job) => $job->webhook->is($webhook));
    Queue::assertPushed(SendHttpWebhookJob::class, 1);
});

// -- registry surface --------------------------------------------------------------

it('registers the M1 webhook types', function () {
    expect(array_keys(SendWebhookJob::WEBHOOK_SERVICES))->toBe([
        'customer.created',
        'customer.updated',
        'invoice.created',
        'invoice.drafted',
        'subscription.started',
        'subscription.updated',
        'subscription.terminated',
        'subscription.canceled',
    ]);
});
