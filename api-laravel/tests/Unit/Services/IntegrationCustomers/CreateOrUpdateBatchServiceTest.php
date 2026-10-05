<?php

declare(strict_types=1);

use App\Jobs\IntegrationCustomers\CreateJob;
use App\Models\Customer;
use App\Models\IntegrationCustomers\AnrokCustomer;
use App\Models\Integrations\AnrokIntegration;
use App\Models\Integrations\AvalaraIntegration;
use App\Models\Organization;
use App\Services\IntegrationCustomers\CreateOrUpdateBatchService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Port of Rails' spec/services/integration_customers specs (the tax-provider
 * legs) — the integration_customers payload entries on customer
 * create/update dispatch the per-provider sync jobs.
 */
beforeEach(function (): void {
    Queue::fake();
});

it('dispatches the anrok create job for a synced customer entry', function (): void {
    $organization = Organization::factory()->create();
    $integration = AnrokIntegration::factory()->create([
        'organization_id' => $organization->id,
        'code' => 'anrok',
    ]);
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);

    CreateOrUpdateBatchService::call(
        integration_customers: [[
            'integration_code' => 'anrok',
            'integration_type' => 'anrok',
            'sync_with_provider' => true,
        ]],
        customer: $customer,
        new_customer: true,
    );

    Queue::assertPushed(CreateJob::class, fn (CreateJob $job) => $job->integration->id === $integration->id
        && $job->customer->id === $customer->id);
});

it('creates the anrok integration customer row through the job', function (): void {
    $organization = Organization::factory()->create();
    $integration = AnrokIntegration::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);

    // Run the job's handler directly (Rails' spec style for the service).
    $result = App\Services\IntegrationCustomers\AnrokService::call(
        integration: $integration,
        customer: $customer,
        subsidiary_id: null,
        params: [],
    );

    expect($result->success())->toBeTrue()
        ->and($result->integration_customer)->toBeInstanceOf(AnrokCustomer::class)
        ->and($result->integration_customer->settings['sync_with_provider'])->toBeTrue();
});

it('creates the avalara integration customer from the provider contact', function (): void {
    Http::fake([
        'https://api.nango.dev/v1/avalara/contacts' => Http::response([
            'succeededContacts' => [['id' => 'contact-123', 'email' => 'billing@acme.test']],
        ]),
    ]);

    $organization = Organization::factory()->create();
    $integration = AvalaraIntegration::factory()->create([
        'organization_id' => $organization->id,
        'settings' => ['company_code' => 'DEFAULT', 'company_id' => '42'],
    ]);
    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Acme',
        'email' => 'billing@acme.test',
        'city' => 'Paris',
        'zipcode' => '75011',
        'country' => 'FR',
        'state' => 'IDF',
    ]);

    $result = App\Services\IntegrationCustomers\AvalaraService::call(
        integration: $integration,
        customer: $customer,
        subsidiary_id: null,
        params: [],
    );

    expect($result->success())->toBeTrue()
        ->and($result->integration_customer->external_customer_id)->toBe('contact-123');

    // The contact payload carried the avalara company id and the address.
    $sent = Http::recorded()[0][0]->data();

    expect($sent[0]['company_id'])->toBe(42)
        ->and($sent[0]['external_id'])->toBe($customer->id)
        ->and($sent[0]['city'])->toBe('Paris')
        ->and($sent[0]['tax_number'])->toBeNull();
});

it('does nothing without integration customers entries', function (): void {
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);

    CreateOrUpdateBatchService::call(
        integration_customers: null,
        customer: $customer,
        new_customer: true,
    );

    Queue::assertNothingPushed();
});

it('skips partner accounts', function (): void {
    $organization = Organization::factory()->create();
    AnrokIntegration::factory()->create(['organization_id' => $organization->id, 'code' => 'anrok']);
    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'account_type' => App\Enums\AccountType::Partner->value,
    ]);

    CreateOrUpdateBatchService::call(
        integration_customers: [[
            'integration_code' => 'anrok',
            'integration_type' => 'anrok',
            'sync_with_provider' => true,
        ]],
        customer: $customer,
        new_customer: true,
    );

    Queue::assertNothingPushed();
});
