<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Organization;
use Illuminate\Support\Facades\Http;
use App\Models\IntegrationCustomer;
use App\Models\IntegrationCustomers\XeroCustomer;
use App\Models\IntegrationCustomers\NetsuiteCustomer;
use App\Services\IntegrationCustomers\XeroService;
use App\Services\IntegrationCustomers\NetsuiteService;
use App\Services\Integrations\Aggregator\Contacts\Payloads\Netsuite as NetsuitePayload;

/**
 * Ports of Rails' spec/services/integration_customers/{xero,netsuite}
 * _service_spec.rb and the contacts payloads spec for the accounting
 * providers — over Http::fake.
 */
function accountingCustomerFixture(Organization $organization): array
{
    $xeroIntegration = \App\Models\Integrations\XeroIntegration::factory()->forOrganization($organization)->create();
    $netsuiteIntegration = \App\Models\Integrations\NetsuiteIntegration::factory()->forOrganization($organization)->create();
    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'external_id' => 'cus_lago_1',
        'name' => 'Acme',
        'email' => 'billing@acme.com',
        'city' => null,
        'zipcode' => null,
        'country' => null,
        'state' => null,
        'phone' => null,
        'firstname' => null,
        'lastname' => null,
        'address_line1' => null,
        'address_line2' => null,
    ]);

    return [$customer, $xeroIntegration, $netsuiteIntegration];
}

beforeEach(function (): void {
    putenv('NANGO_SECRET_KEY=secret');
    $_ENV['NANGO_SECRET_KEY'] = 'secret';
});

afterEach(function (): void {
    putenv('NANGO_SECRET_KEY');
    unset($_ENV['NANGO_SECRET_KEY']);
});

it('creates a xero integration customer from the nango contact', function (): void {
    $organization = Organization::factory()->create();
    [$customer, $xeroIntegration] = accountingCustomerFixture($organization);

    $captured = null;
    Http::fake(function ($request) use (&$captured) {
        if ($request->url() !== 'https://api.nango.dev/v1/xero/contacts') {
            return Http::response('', 500);
        }

        $captured = $request;

        return Http::response(['succeededContacts' => [['id' => 'xero-contact-1', 'email' => 'billing@acme.com']]]);
    });

    $result = XeroService::call(
        integration: $xeroIntegration,
        customer: $customer,
        subsidiary_id: null,
    );

    expect($result->success())->toBeTrue()
        ->and($result->integration_customer)->toBeInstanceOf(XeroCustomer::class)
        ->and($result->integration_customer->external_customer_id)->toBe('xero-contact-1')
        ->and($result->integration_customer->integration_id)->toBe($xeroIntegration->id)
        ->and($result->integration_customer->customer_id)->toBe($customer->id)
        ->and($result->integration_customer->type)->toBe(IntegrationCustomer::XERO_TYPE)
        ->and($result->integration_customer->category)->toBe('accounting');

    expect(XeroCustomer::query()->count())->toBe(1);

    // Rails: the shared contact payload (name/city/zip/country/state/email/phone).
    expect($captured->data())->toBe([[
        'name' => 'Acme',
        'city' => null,
        'zip' => null,
        'country' => null,
        'state' => null,
        'email' => 'billing@acme.com',
        'phone' => null,
    ]]);
});

it('creates a netsuite integration customer with the subsidiary', function (): void {
    $organization = Organization::factory()->create();
    [$customer, , $netsuiteIntegration] = accountingCustomerFixture($organization);

    $captured = null;
    Http::fake(function ($request) use (&$captured) {
        if ($request->url() !== 'https://api.nango.dev/v1/netsuite/contacts') {
            return Http::response('', 500);
        }

        $captured = $request;

        return Http::response('"netsuite-contact-1"');
    });

    $result = NetsuiteService::call(
        integration: $netsuiteIntegration,
        customer: $customer,
        subsidiary_id: 'sub-1',
    );

    expect($result->success())->toBeTrue()
        ->and($result->integration_customer)->toBeInstanceOf(NetsuiteCustomer::class)
        ->and($result->integration_customer->external_customer_id)->toBe('netsuite-contact-1')
        ->and($result->integration_customer->subsidiaryId())->toBe('sub-1');

    expect(NetsuiteCustomer::query()->count())->toBe(1);

    // Rails: the restlet customer record shape (company, no address lines
    // for an empty billing/shipping address).
    expect($captured->data())->toBe([
        'type' => 'customer',
        'isDynamic' => true,
        'columns' => [
            'isperson' => 'F',
            'subsidiary' => 'sub-1',
            'custentity_lago_id' => $customer->id,
            'custentity_lago_sf_id' => null,
            'custentity_lago_customer_link' => config('lago.front_url').'/'.$organization->slug.'/customer/'.$customer->id,
            'email' => 'billing@acme.com',
            'phone' => null,
            'entityid' => 'cus_lago_1',
            'autoname' => false,
            'companyname' => 'Acme',
        ],
        'options' => ['ignoreMandatoryFields' => false],
    ]);
});

it('returns the existing netsuite integration customer as-is', function (): void {
    $organization = Organization::factory()->create();
    [$customer, , $netsuiteIntegration] = accountingCustomerFixture($organization);

    $existing = NetsuiteCustomer::factory()->forIntegration($netsuiteIntegration)->forCustomer($customer)->create();

    Http::fake();

    $result = NetsuiteService::call(
        integration: $netsuiteIntegration,
        customer: $customer,
        subsidiary_id: null,
    );

    expect($result->success())->toBeTrue()
        ->and($result->integration_customer->id)->toBe($existing->id);

    Http::assertNothingSent();
});

it('builds the netsuite individual customer columns with address lines', function (): void {
    $organization = Organization::factory()->create();
    [$customer] = accountingCustomerFixture($organization);

    $customer->update([
        'customer_type' => \App\Enums\CustomerType::Individual,
        'firstname' => 'Jane',
        'lastname' => str_repeat('D', 60),
        'name' => 'Jane Doe Corp',
        'address_line1' => str_repeat('a', 200),
        'city' => 'Paris',
        'zipcode' => '75001',
        'state' => str_repeat('s', 60),
        'country' => 'FR',
        'shipping_country' => 'US',
    ]);
    $customer->refresh();

    $payload = new NetsuitePayload(
        integration: \App\Models\Integrations\NetsuiteIntegration::factory()->forOrganization($organization)->create(['code' => 'netsuite2']),
        customer: $customer,
        integration_customer: null,
        subsidiary_id: 'sub-9',
    );

    $body = $payload->create_body();

    expect($body['columns']['isperson'])->toBe('T')
        ->and($body['columns']['firstname'])->toBe('Jane')
        ->and($body['columns']['lastname'])->toBe(str_repeat('D', 30))
        ->and($body['columns']['companyname'])->toBe('Jane Doe Corp')
        ->and($body['columns']['subsidiary'])->toBe('sub-9')
        ->and($body['lines'][0]['sublistId'])->toBe('addressbook')
        ->and($body['lines'][0]['lineItems'])->toHaveCount(2)
        ->and($body['lines'][0]['lineItems'][0]['subObject']['addr1'])->toBe(str_repeat('a', 150))
        ->and($body['lines'][0]['lineItems'][0]['defaultbilling'])->toBeTrue()
        ->and($body['lines'][0]['lineItems'][1]['subObject']['country'])->toBe('US');
});
