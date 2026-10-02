<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Jobs\SendWebhookJob;
use App\Models\Organization;
use Illuminate\Support\Facades\Queue;
use App\Services\Customers\CreateService;
use App\Services\Customers\UpdateService;
use App\Services\Customers\UpsertFromApiService;

beforeEach(function (): void {
    Queue::fake();
    App\Support\CurrentContext::reset();
});

it('emits customer.created after creating a customer', function (): void {
    $organization = Organization::factory()->create();

    $result = CreateService::call(organization: $organization, args: [
        'external_id' => 'cus-emit-1',
        'name' => 'Emitted',
    ]);

    expect($result->success())->toBeTrue();

    Queue::assertPushed(SendWebhookJob::class, function (SendWebhookJob $job) use ($result) {
        return $job->webhookType === 'customer.created' && $job->object->is($result->customer);
    });
});

it('emits customer.updated after updating a customer', function (): void {
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->for($organization)->create();

    $result = UpdateService::call(customer: $customer, args: ['name' => 'Renamed']);

    expect($result->success())->toBeTrue();

    Queue::assertPushed(SendWebhookJob::class, function (SendWebhookJob $job) use ($customer) {
        return $job->webhookType === 'customer.updated' && $job->object->is($customer);
    });
});

it('emits customer.created from the upsert for a new customer', function (): void {
    $organization = Organization::factory()->create();

    $result = UpsertFromApiService::call(organization: $organization, params: [
        'external_id' => 'cus-upsert-1',
        'name' => 'Upserted',
    ]);

    expect($result->success())->toBeTrue();

    Queue::assertPushed(SendWebhookJob::class, fn (SendWebhookJob $job) => $job->webhookType === 'customer.created');
});

it('emits customer.updated from the upsert for an existing customer', function (): void {
    $organization = Organization::factory()->create();
    Customer::factory()->for($organization)->create(['external_id' => 'cus-upsert-2']);

    $result = UpsertFromApiService::call(organization: $organization, params: [
        'external_id' => 'cus-upsert-2',
        'name' => 'Upserted again',
    ]);

    expect($result->success())->toBeTrue();

    Queue::assertPushed(SendWebhookJob::class, fn (SendWebhookJob $job) => $job->webhookType === 'customer.updated');
});

it('does not emit any webhook when the organization has no endpoints', function (): void {
    $organization = Organization::factory()->withoutWebhookEndpoint()->create();

    $result = CreateService::call(organization: $organization, args: [
        'external_id' => 'cus-emit-2',
        'name' => 'Silent',
    ]);

    expect($result->success())->toBeTrue();

    Queue::assertNothingPushed();
});
