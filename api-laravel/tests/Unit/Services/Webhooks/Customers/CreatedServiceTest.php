<?php

declare(strict_types=1);

use App\Models\Webhook;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Queue;
use App\Services\Webhooks\Customers\CreatedService;
use App\Services\Webhooks\Customers\UpdatedService;

/**
 * Port of spec/services/webhooks/customers/{created,updated}_service_spec.rb
 * (the "creates webhook" shared example).
 */
beforeEach(function () {
    Queue::fake();

    $this->organization = Organization::factory()->withoutWebhookEndpoint()->create();
    $this->customer = Customer::factory()->for($this->organization)->create();
    $this->endpoint = WebhookEndpoint::factory()->forOrganization($this->organization)->create();
});

it('creates a customer.created webhook with the serialized customer', function () {
    CreatedService::call(object: $this->customer);

    $webhook = Webhook::query()->latest('created_at')->first();

    expect($webhook->payload['webhook_type'])->toBe('customer.created')
        ->and($webhook->payload['object_type'])->toBe('customer')
        ->and($webhook->payload['organization_id'])->toBe($this->organization->id)
        ->and($webhook->payload['customer']['lago_id'])->toBe($this->customer->id)
        ->and($webhook->payload['customer']['external_id'])->toBe($this->customer->external_id)
        ->and($webhook->payload['customer']['name'])->toBe($this->customer->name)
        ->and($webhook->object_id)->toBe($this->customer->id)
        ->and($webhook->object_type)->toBe(Customer::class)
        ->and($webhook->webhookEndpoint->is($this->endpoint))->toBeTrue();

    Queue::assertPushed(App\Jobs\SendHttpWebhookJob::class, 1);
});

it('creates a customer.updated webhook with the serialized customer', function () {
    UpdatedService::call(object: $this->customer);

    $webhook = Webhook::query()->latest('created_at')->first();

    expect($webhook->payload['webhook_type'])->toBe('customer.updated')
        ->and($webhook->payload['object_type'])->toBe('customer')
        ->and($webhook->payload['organization_id'])->toBe($this->organization->id)
        ->and($webhook->payload['customer']['lago_id'])->toBe($this->customer->id);

    Queue::assertPushed(App\Jobs\SendHttpWebhookJob::class, 1);
});
