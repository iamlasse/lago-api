<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Organization;
use Illuminate\Support\Facades\Http;
use App\Models\Integrations\HubspotIntegration;
use App\Models\IntegrationCustomers\HubspotCustomer;
use App\Services\Integrations\Aggregator\Companies\UpdateService;

/**
 * Port of Rails' spec/services/integrations/aggregator/companies/
 * update_service_spec.rb — the Nango company update (PUT on the external
 * company id), over Http::fake.
 */
function companyUpdateFixture(Organization $organization): array
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

    $integrationCustomer = HubspotCustomer::factory()->create([
        'organization_id' => $organization->id,
        'integration_id' => $integration->id,
        'customer_id' => $customer->id,
        'external_customer_id' => '2e50c200-9a54-4a66-b241-1e75fb87373f',
        'settings' => ['targeted_object' => 'companies'],
    ]);

    return [$customer, $integration, $integrationCustomer];
}

beforeEach(function (): void {
    putenv('NANGO_SECRET_KEY=secret');
    $_ENV['NANGO_SECRET_KEY'] = 'secret';
});

afterEach(function (): void {
    putenv('NANGO_SECRET_KEY');
    unset($_ENV['NANGO_SECRET_KEY']);
});

it('refuses an individual customer', function (): void {
    $organization = Organization::factory()->create();
    $integration = HubspotIntegration::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'customer_type' => 'individual',
    ]);
    $integrationCustomer = HubspotCustomer::factory()->create([
        'organization_id' => $organization->id,
        'integration_id' => $integration->id,
        'customer_id' => $customer->id,
    ]);

    expect(fn () => UpdateService::call(
        integration: $integration,
        integration_customer: $integrationCustomer,
    ))->toThrow(InvalidArgumentException::class);
});

it('updates the company through the external id', function (): void {
    config(['lago.front_url' => 'https://app.getlago.com']);

    $organization = Organization::factory()->create();
    [$customer, $integration, $integrationCustomer] = companyUpdateFixture($organization);

    $captured = null;
    Http::fake(function ($request) use (&$captured) {
        if (str_contains((string) $request->url(), '/v1/hubspot/properties')) {
            return Http::response('{}');
        }

        if ($request->url() !== 'https://api.nango.dev/v1/hubspot/companies') {
            return Http::response('', 500);
        }

        $captured = $request;

        return Http::response(companiesSuccessBody());
    });

    $result = UpdateService::call(integration: $integration, integration_customer: $integrationCustomer);

    expect($result->failure())->toBeFalse();

    $body = $captured->data();

    expect($body['companyId'])->toBe('2e50c200-9a54-4a66-b241-1e75fb87373f');

    $properties = $body['input']['properties'];

    expect($properties['lago_customer_id'])->toBe($customer->id)
        ->and($properties['lago_customer_external_id'])->toBe('cus_lago_12345')
        ->and($properties['lago_customer_link'])->toBe(
            'https://app.getlago.com/'.$organization->slug.'/customer/'.$customer->id
        );

    // The update PUT stamps the deploy-properties version too.
    expect($integration->refresh()->companiesPropertiesVersion())->toBe(1);
});
