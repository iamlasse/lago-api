<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Organization;
use Illuminate\Support\Facades\Http;
use App\Models\Integrations\HubspotIntegration;
use App\Services\Integrations\Aggregator\Companies\CreateService;

/**
 * Port of Rails' spec/services/integrations/aggregator/companies/
 * create_service_spec.rb — the Nango company creation for the HubSpot
 * company-customer sync leg, over Http::fake.
 */
function companyFixture(Organization $organization): array
{
    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'customer_type' => 'company',
        'name' => 'Acme Corp',
        'external_id' => 'cus_lago_12345',
        'email' => 'billing@acme.test',
        'url' => 'https://www.acme.test',
        'tax_identification_number' => 'FR12345678901',
    ]);

    $integration = HubspotIntegration::factory()->create(['organization_id' => $organization->id]);

    return [$customer, $integration];
}

function companiesSuccessBody(): string
{
    return (string) file_get_contents(base_path('tests/fixtures/IntegrationAggregator/companies/success_hash_response.json'));
}

function companiesFailureBody(): string
{
    return (string) file_get_contents(base_path('tests/fixtures/IntegrationAggregator/companies/failure_hash_response.json'));
}

beforeEach(function (): void {
    putenv('NANGO_SECRET_KEY=secret');
    $_ENV['NANGO_SECRET_KEY'] = 'secret';
});

afterEach(function (): void {
    putenv('NANGO_SECRET_KEY');
    unset($_ENV['NANGO_SECRET_KEY']);
});

it('creates the company and returns the contact id', function (): void {
    config(['lago.front_url' => 'https://app.getlago.com']);

    $organization = Organization::factory()->create();
    [$customer, $integration] = companyFixture($organization);

    $captured = null;
    Http::fake(function ($request) use (&$captured) {
        if (str_contains((string) $request->url(), '/v1/hubspot/properties')) {
            // The deploy-properties leg succeeds (then the version stamp
            // keeps the second sync from repeating it).
            return Http::response('{}');
        }

        if ($request->url() !== 'https://api.nango.dev/v1/hubspot/companies') {
            return Http::response('', 500);
        }

        $captured = $request;

        return Http::response(companiesSuccessBody());
    });

    $result = CreateService::call(integration: $integration, customer: $customer, subsidiary_id: null);

    expect($result->failure())->toBeFalse()
        ->and($result->contact_id)->toBe('2e50c200-9a54-4a66-b241-1e75fb87373f')
        ->and($result->email)->toBe('roger@rogers.com');

    // The request carried the hubspot headers and the company properties.
    expect($captured->header('Provider-Config-Key'))->toBe(['hubspot'])
        ->and($captured->header('Authorization'))->toBe(['Bearer secret'])
        ->and($captured->header('Connection-Id'))->toBe([$integration->getFromSecrets('connection_id')]);

    $body = $captured->data()['properties'];

    expect($body['lago_customer_id'])->toBe($customer->id)
        ->and($body['lago_customer_external_id'])->toBe('cus_lago_12345')
        ->and($body['lago_billing_email'])->toBe('billing@acme.test')
        ->and($body['lago_tax_identification_number'])->toBe('FR12345678901')
        ->and($body['lago_customer_link'])->toBe(
            'https://app.getlago.com/'.$organization->slug.'/customer/'.$customer->id
        )
        ->and($body['name'])->toBe('Acme Corp')
        ->and($body['domain'])->toBe('www.acme.test');

    // The deploy-properties leg stamped the version marker.
    expect($integration->refresh()->companiesPropertiesVersion())->toBe(1);
});

it('delivers the success webhook without failing on a validation failure', function (): void {
    $organization = Organization::factory()->create();
    [$customer, $integration] = companyFixture($organization);

    Http::fake([
        '*/v1/hubspot/properties' => Http::response('{}'),
        'https://api.nango.dev/v1/hubspot/companies' => Http::response(companiesFailureBody()),
    ]);

    $result = CreateService::call(integration: $integration, customer: $customer, subsidiary_id: null);

    // Rails: the failed company keeps the service successful (the contact
    // stays unresolved) — only the error webhook goes out.
    expect($result->failure())->toBeFalse()
        ->and($result->contact_id)->toBeNull();
});

it('skips the properties deploy when the version is already stamped', function (): void {
    $organization = Organization::factory()->create();
    [$customer, $integration] = companyFixture($organization);
    $integration->settings = array_merge((array) $integration->settings, ['companies_properties_version' => 1]);
    $integration->save();

    $deployed = false;
    Http::fake(function ($request) use (&$deployed) {
        if (str_contains((string) $request->url(), '/v1/hubspot/properties')) {
            $deployed = true;

            return Http::response('{}');
        }

        return Http::response(companiesSuccessBody());
    });

    $result = CreateService::call(integration: $integration, customer: $customer, subsidiary_id: null);

    expect($result->failure())->toBeFalse()
        ->and($result->contact_id)->toBe('2e50c200-9a54-4a66-b241-1e75fb87373f')
        ->and($deployed)->toBeFalse();
});
