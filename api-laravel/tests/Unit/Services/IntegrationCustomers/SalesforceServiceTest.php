<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Organization;
use App\Models\Integrations\SalesforceIntegration;
use App\Models\IntegrationCustomers\SalesforceCustomer;
use App\Services\IntegrationCustomers\SalesforceService;

/**
 * Port of Rails' spec/services/integration_customers/salesforce_service_spec.rb
 * — Salesforce doesn't need to reach a provider at creation: only the
 * integration customer row is stored (marked sync_with_provider).
 */
it('creates the integration customer row without reaching a provider', function (): void {
    Http::fake(fn () => Http::response('', 500));

    $organization = Organization::factory()->create();
    $integration = SalesforceIntegration::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);

    $result = SalesforceService::call(
        integration: $integration,
        customer: $customer,
        subsidiary_id: null,
        params: [],
    );

    expect($result->success())->toBeTrue()
        ->and($result->integration_customer)->toBeInstanceOf(SalesforceCustomer::class)
        ->and($result->integration_customer->integration_id)->toBe($integration->id)
        ->and($result->integration_customer->customer_id)->toBe($customer->id)
        ->and($result->integration_customer->settings['sync_with_provider'])->toBeTrue()
        ->and($result->integration_customer->category)->toBe('crm');

    expect(SalesforceCustomer::query()->count())->toBe(1);
});
