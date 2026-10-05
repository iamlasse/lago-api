<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Organization;
use Illuminate\Support\Facades\Http;
use App\Models\Integrations\HubspotIntegration;
use App\Models\IntegrationCustomers\HubspotCustomer;
use App\Services\IntegrationCustomers\HubspotService;

/**
 * Port of Rails' spec/services/integration_customers/hubspot_service_spec.rb
 * — the CRM object is created through the Nango contacts or companies call
 * (by targeted object), over Http::fake.
 */
function hubspotCustomerFixture(Organization $organization): array
{
    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'customer_type' => 'company',
        'name' => 'Acme Corp',
        'external_id' => 'cus_lago_12345',
        'email' => 'billing@acme.test',
        'url' => 'https://www.acme.test',
    ]);

    $integration = HubspotIntegration::factory()->create(['organization_id' => $organization->id]);

    return [$customer, $integration];
}

beforeEach(function (): void {
    putenv('NANGO_SECRET_KEY=secret');
    $_ENV['NANGO_SECRET_KEY'] = 'secret';
});

afterEach(function (): void {
    putenv('NANGO_SECRET_KEY');
    unset($_ENV['NANGO_SECRET_KEY']);
});

it('creates the integration customer through the companies collector', function (): void {
    config(['lago.front_url' => 'https://app.getlago.com']);

    $organization = Organization::factory()->create();
    [$customer, $integration] = hubspotCustomerFixture($organization);

    Http::fake([
        '*/v1/hubspot/properties' => Http::response('{}'),
        'https://api.nango.dev/v1/hubspot/companies' => Http::response([
            'succeededCompanies' => [['id' => 'company-123', 'email' => 'roger@rogers.com']],
        ]),
    ]);

    $result = HubspotService::call(
        integration: $integration,
        customer: $customer,
        subsidiary_id: null,
        params: ['targeted_object' => 'companies'],
    );

    expect($result->success())->toBeTrue()
        ->and($result->integration_customer)->toBeInstanceOf(HubspotCustomer::class)
        ->and($result->integration_customer->external_customer_id)->toBe('company-123')
        ->and($result->integration_customer->integration_id)->toBe($integration->id)
        ->and($result->integration_customer->customer_id)->toBe($customer->id)
        ->and($result->integration_customer->email())->toBe('roger@rogers.com')
        ->and($result->integration_customer->targetedObject())->toBe('companies');

    expect(HubspotCustomer::query()->count())->toBe(1);
});

it('routes an individual customer to the contacts collector', function (): void {
    $organization = Organization::factory()->create();
    $integration = HubspotIntegration::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'customer_type' => 'individual',
        'email' => 'roger@rogers.com',
    ]);

    $captured = null;
    Http::fake(function ($request) use (&$captured) {
        if (str_contains((string) $request->url(), '/v1/hubspot/properties')) {
            return Http::response('{}');
        }

        if ($request->url() !== 'https://api.nango.dev/v1/hubspot/contacts') {
            return Http::response('', 500);
        }

        $captured = $request;

        return Http::response([
            'succeededContacts' => [['id' => 'contact-123', 'email' => 'roger@rogers.com']],
        ]);
    });

    // Rails: an individual customer defaults to the contacts collector.
    $result = HubspotService::call(
        integration: $integration,
        customer: $customer,
        subsidiary_id: null,
        params: [],
    );

    expect($result->success())->toBeTrue()
        ->and($result->integration_customer->external_customer_id)->toBe('contact-123')
        ->and($result->integration_customer->targetedObject())->toBe('contacts');

    expect($captured->url())->toBe('https://api.nango.dev/v1/hubspot/contacts');
});

it('returns the failure without creating a row when the provider call fails', function (): void {
    $organization = Organization::factory()->create();
    [$customer, $integration] = hubspotCustomerFixture($organization);

    Http::fake([
        '*/v1/hubspot/properties' => Http::response('{}'),
        'https://api.nango.dev/v1/hubspot/companies' => Http::response(['error' => ['payload' => ['message' => 'boom']]], 500),
    ]);

    $result = HubspotService::call(
        integration: $integration,
        customer: $customer,
        subsidiary_id: null,
        params: ['targeted_object' => 'companies'],
    );

    expect($result->failure())->toBeTrue();

    expect(HubspotCustomer::query()->count())->toBe(0);
});
