<?php

declare(strict_types=1);

use App\Models\Webhook;
use App\Models\Customer;
use Illuminate\Support\Env;
use App\Jobs\SendWebhookJob;
use App\Models\Organization;
use App\Models\WebhookEndpoint;
use App\Jobs\SendHttpWebhookJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();

    $this->organization = Organization::factory()->withoutWebhookEndpoint()->create();
    $this->endpoint = WebhookEndpoint::factory()->forOrganization($this->organization)->create();
    $this->customer = Customer::factory()->for($this->organization)->create();
});

// -- performLater (Rails: the perform_later override) -----------------------------

it('does not enqueue when the organization has no webhook endpoints', function (): void {
    $organization = Organization::factory()->withoutWebhookEndpoint()->create();
    $customer = Customer::factory()->for($organization)->create();

    SendWebhookJob::performLater('customer.created', $customer);

    Queue::assertNothingPushed();
})->group('ledger:job:SendWebhookJob');

it('enqueues when the organization has webhook endpoints', function (): void {
    SendWebhookJob::performLater('customer.created', $this->customer, ['key' => 'value']);

    Queue::assertPushed(SendWebhookJob::class, fn (SendWebhookJob $job) => $job->webhookType === 'customer.created'
        && $job->object->is($this->customer)
        && $job->options === ['key' => 'value']
        && $job->webhookId === null);
})->group('ledger:job:SendWebhookJob');

it('enqueues with a webhook id even when endpoints are checked', function (): void {
    $webhook = Webhook::factory()->for($this->endpoint, 'webhookEndpoint')->create();

    SendWebhookJob::performLater('customer.created', $this->customer, [], $webhook->id);

    Queue::assertPushed(SendWebhookJob::class, fn (SendWebhookJob $job) => $job->webhookId === $webhook->id);
})->group('ledger:job:SendWebhookJob');

// -- queue_for -------------------------------------------------------------------

it('uses the webhook queue by default', function (): void {
    expect(SendWebhookJob::queueFor('alert.triggered'))->toBe('webhook')
        ->and(SendWebhookJob::queueFor('invoice.created'))->toBe('webhook')
        ->and(SendWebhookJob::queueFor())->toBe('webhook');
})->group('ledger:job:SendWebhookJob');

it('uses the dedicated worker queues when SIDEKIQ_WEBHOOK is true', function (): void {
    $_ENV['SIDEKIQ_WEBHOOK'] = 'true';
    $_SERVER['SIDEKIQ_WEBHOOK'] = 'true';

    try {
        expect(SendWebhookJob::queueFor('alert.triggered'))->toBe('webhook_worker_high_priority')
            ->and(SendWebhookJob::queueFor('invoice.created'))->toBe('webhook_worker');
    } finally {
        Env::getRepository()->clear('SIDEKIQ_WEBHOOK');

        unset($_ENV['SIDEKIQ_WEBHOOK'], $_SERVER['SIDEKIQ_WEBHOOK']);
    }
})->group('ledger:job:SendWebhookJob');

it('runs the job on the queue chosen at construction', function (): void {
    SendWebhookJob::performLater('customer.created', $this->customer);

    Queue::assertPushed(SendWebhookJob::class, fn ($job) => $job->queue === 'webhook');
})->group('ledger:job:SendWebhookJob');

// -- perform ---------------------------------------------------------------------

it('dispatches the builder service for a registered type', function (): void {
    $job = new SendWebhookJob('customer.created', $this->customer);
    $job->handle();

    // The customer.created builder fanned out to the endpoint.
    Queue::assertPushed(SendHttpWebhookJob::class, 1);

    $webhook = Webhook::query()->sole();
    expect($webhook->webhook_type)->toBe('customer.created')
        ->and($webhook->payload['customer']['lago_id'])->toBe($this->customer->id);
})->group('ledger:job:SendWebhookJob', 'ledger:job:SendHttpWebhookJob');

it('raises for an unknown webhook type', function (): void {
    $job = new SendWebhookJob('totally.unknown', $this->customer);

    expect(fn () => $job->handle())->toThrow(LogicException::class);
})->group('ledger:job:SendWebhookJob');

it('routes legacy webhook_id enqueues straight to the http job', function (): void {
    $webhook = Webhook::factory()->for($this->endpoint, 'webhookEndpoint')->create();

    (new SendWebhookJob('customer.created', $this->customer, [], $webhook->id))->handle();

    Queue::assertPushed(SendHttpWebhookJob::class, fn ($job) => $job->webhook->is($webhook));
    Queue::assertPushed(SendHttpWebhookJob::class, 1);
})->group('ledger:job:SendWebhookJob', 'ledger:job:SendHttpWebhookJob');

// -- registry surface --------------------------------------------------------------

it('registers the M1 webhook types', function (): void {
    expect(array_keys(SendWebhookJob::WEBHOOK_SERVICES))->toBe([
        'customer.created',
        'customer.updated',
        'invoice.created',
        'invoice.drafted',
        'invoice.generated',
        'subscription.started',
        'subscription.updated',
        'subscription.terminated',
        'subscription.canceled',
        'subscription.termination_alert',
        'wallet.created',
        'wallet.updated',
        'wallet.terminated',
        'wallet_transaction.created',
        'wallet_transaction.updated',
        'wallet.depleted_ongoing_balance',
        // payment-receipts + dunning-campaigns slice.
        'payment_receipt.created',
        'payment_receipt.generated',
        'dunning_campaign.finished',
        // usage-monitoring slice.
        'alert.triggered',
        'subscription.usage_threshold_reached',
    ]);
})->group('ledger:job:SendWebhookJob');

// -- SendHttpWebhookJob (port of app/jobs/send_http_webhook_job.rb) ----------------

it('proxies queueFor to the shared webhook queue map', function (): void {
    expect(SendHttpWebhookJob::queueFor('alert.triggered'))->toBe('webhook')
        ->and(SendHttpWebhookJob::queueFor('invoice.created'))->toBe('webhook')
        ->and(SendHttpWebhookJob::queueFor())->toBe('webhook');
})->group('ledger:job:SendHttpWebhookJob');

it('routes the http job to the dedicated worker queues when SIDEKIQ_WEBHOOK is true', function (): void {
    $_ENV['SIDEKIQ_WEBHOOK'] = 'true';
    $_SERVER['SIDEKIQ_WEBHOOK'] = 'true';

    try {
        expect(SendHttpWebhookJob::queueFor('alert.triggered'))->toBe('webhook_worker_high_priority')
            ->and(SendHttpWebhookJob::queueFor('invoice.created'))->toBe('webhook_worker');
    } finally {
        Env::getRepository()->clear('SIDEKIQ_WEBHOOK');

        unset($_ENV['SIDEKIQ_WEBHOOK'], $_SERVER['SIDEKIQ_WEBHOOK']);
    }
})->group('ledger:job:SendHttpWebhookJob');

it('constructs the http job on the queue chosen for its webhook type', function (): void {
    $webhook = Webhook::factory()->for($this->endpoint, 'webhookEndpoint')->create();

    $job = new SendHttpWebhookJob($webhook);

    expect($job->queue)->toBe('webhook')
        ->and($job->webhook->is($webhook))->toBeTrue();
})->group('ledger:job:SendHttpWebhookJob');

it('performs the http delivery for its webhook', function (): void {
    $_ENV['LAGO_WEBHOOK_ALLOW_PRIVATE_URLS'] = 'true';
    $_SERVER['LAGO_WEBHOOK_ALLOW_PRIVATE_URLS'] = 'true';

    // :hmac — the jwt signature needs the RSA key pair this suite does not
    // configure (see SendHttpServiceTest).
    $endpoint = WebhookEndpoint::factory()->forOrganization($this->organization)->create([
        'signature_algo' => 1,
        'webhook_url' => 'https://wh.test.com',
    ]);

    $webhook = Webhook::factory()->for($endpoint, 'webhookEndpoint')->create([
        'endpoint' => 'https://wh.test.com',
    ]);

    Http::fake(['https://wh.test.com' => Http::response('ok', 200)]);

    (new SendHttpWebhookJob($webhook))->handle();

    $webhook = $webhook->fresh();

    expect($webhook->succeeded())->toBeTrue()
        ->and($webhook->http_status)->toBe(200);

    // A successful attempt does not re-enqueue the retry job.
    Queue::assertNothingPushed();

    Env::getRepository()->clear('LAGO_WEBHOOK_ALLOW_PRIVATE_URLS');

    unset($_ENV['LAGO_WEBHOOK_ALLOW_PRIVATE_URLS'], $_SERVER['LAGO_WEBHOOK_ALLOW_PRIVATE_URLS']);
})->group('ledger:job:SendHttpWebhookJob');
