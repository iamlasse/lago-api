<?php

declare(strict_types=1);

use App\Models\Webhook;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\WebhookEndpoint;
use App\Jobs\SendHttpWebhookJob;
use Illuminate\Support\Facades\Queue;
use App\Services\Webhooks\BaseService;

/**
 * Port of spec/services/webhooks/base_service_spec.rb — a dummy fan-out
 * service (Rails: WebhooksSpec::DummyClass).
 */
class DummyWebhookService extends BaseService
{
    protected function objectSerializer(): array
    {
        return ['lago_id' => $this->object->id];
    }

    protected function webhookType(): string
    {
        return 'dummy.test';
    }

    protected function objectType(): string
    {
        return 'dummy';
    }
}

beforeEach(function () {
    Queue::fake();

    $this->organization = Organization::factory()->withoutWebhookEndpoint()->create();
    $this->customer = Customer::factory()->for($this->organization)->create();
    $this->endpoint = WebhookEndpoint::factory()->forOrganization($this->organization)->create();
    $this->organization->refresh();
});

it('creates a pending webhook', function () {
    DummyWebhookService::call(object: $this->customer);

    $webhook = Webhook::query()->latest('created_at')->first();

    expect($webhook->pending())->toBeTrue()
        ->and($webhook->retries)->toBe(0)
        ->and($webhook->webhook_type)->toBe('dummy.test')
        ->and($webhook->endpoint)->toBe($webhook->webhookEndpoint->webhook_url)
        ->and($webhook->object_id)->toBe($this->customer->id)
        ->and($webhook->object_type)->toBe($this->customer::class)
        ->and($webhook->http_status)->toBeNull()
        ->and($webhook->getAttributes()['response'])->toBeNull()
        ->and(array_keys($webhook->payload))->toBe(['webhook_type', 'object_type', 'organization_id', 'dummy'])
        ->and($webhook->payload['dummy'])->toBe(['lago_id' => $this->customer->id])
        ->and($webhook->organization_id)->toBe($this->organization->id);

    Queue::assertPushed(SendHttpWebhookJob::class, 1);
});

it('creates one webhook row per webhook endpoint', function () {
    WebhookEndpoint::factory()->forOrganization($this->organization)->create();
    $this->customer->refresh();

    DummyWebhookService::call(object: $this->customer);

    expect(Webhook::query()->count())->toBe(2);

    Queue::assertPushed(SendHttpWebhookJob::class, 2);
});

it('does not fan out when the organization has no webhook endpoint', function () {
    WebhookEndpoint::query()->where('organization_id', $this->organization->id)->delete();

    DummyWebhookService::call(object: $this->customer);

    expect(Webhook::query()->where('object_id', $this->customer->id)->doesntExist())->toBeTrue();

    Queue::assertNothingPushed();
});

it('skips the fan-out early when the organization has no webhook endpoints', function () {
    $organization = Organization::factory()->withoutWebhookEndpoint()->create();
    $customer = Customer::factory()->for($organization)->create();

    DummyWebhookService::call(object: $customer);

    expect(Webhook::query()->where('object_id', $customer->id)->doesntExist())->toBeTrue();
});

it('creates only one webhook when an endpoint was deleted mid fan-out', function () {
    $extraEndpoint = WebhookEndpoint::factory()->forOrganization($this->organization)->create();

    // Preload the webhook endpoints, then delete one to simulate the race
    // the Rails rescue (ActiveRecord::InvalidForeignKey) guards against.
    $this->organization->refresh()->webhookEndpoints;
    WebhookEndpoint::query()->whereKey($extraEndpoint->id)->delete();

    DummyWebhookService::call(object: $this->customer);

    expect(Webhook::query()->count())->toBe(1);

    Queue::assertPushed(SendHttpWebhookJob::class, 1);
});

it('filters webhooks by the endpoint event types', function () {
    // Not matching: skipped.
    DB::table('webhook_endpoints')->where('id', $this->endpoint->id)->update(['event_types' => '{other.type}']);
    $this->endpoint->refresh();

    DummyWebhookService::call(object: $this->customer);

    expect(Webhook::query()->where('object_id', $this->customer->id)->doesntExist())->toBeTrue();

    Queue::assertNothingPushed();
});

it('creates the webhook when the event type matches', function () {
    DB::table('webhook_endpoints')->where('id', $this->endpoint->id)->update(['event_types' => '{dummy.test}']);
    $this->endpoint->refresh();

    DummyWebhookService::call(object: $this->customer);

    expect(Webhook::query()->where('object_id', $this->customer->id)->exists())->toBeTrue();

    Queue::assertPushed(SendHttpWebhookJob::class, 1);
});

it('does not create the webhook when event_types is empty', function () {
    DB::table('webhook_endpoints')->where('id', $this->endpoint->id)->update(['event_types' => '{}']);
    $this->endpoint->refresh();

    DummyWebhookService::call(object: $this->customer);

    expect(Webhook::query()->where('object_id', $this->customer->id)->doesntExist())->toBeTrue();
});

it('creates the webhook when event_types is null', function () {
    DB::table('webhook_endpoints')->where('id', $this->endpoint->id)->update(['event_types' => null]);
    $this->endpoint->refresh();

    DummyWebhookService::call(object: $this->customer);

    expect(Webhook::query()->where('object_id', $this->customer->id)->exists())->toBeTrue();
});

it('routes the http job on the webhook queue', function () {
    DummyWebhookService::call(object: $this->customer);

    Queue::assertPushed(SendHttpWebhookJob::class, fn ($job) => $job->queue === 'webhook');
});
