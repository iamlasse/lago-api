<?php

declare(strict_types=1);

uses()->group(
    'ledger:rest:POST:/api/v1/customers',
    'ledger:rest:GET:/api/v1/customers',
    'ledger:rest:GET:/api/v1/customers/:external_id',
    'ledger:rest:DELETE:/api/v1/customers/:external_id',
);

use App\Models\Customer;
use Illuminate\Support\Str;
use App\Models\Organization;
use App\Models\BillingEntity;
use App\Models\CustomerMetadata;

/**
 * Port of Rails' spec/requests/api/v1/customers_controller_spec.rb.
 */
function customerOrganization(array $attributes = []): array
{
    $organization = Organization::factory()->create($attributes);

    return [$organization, $organization->apiKeys()->first()];
}

function getWithToken(string $path, array $params = [], ?string $token = null): Illuminate\Testing\TestResponse
{
    $url = $params === [] ? $path : $path.'?'.http_build_query($params);

    return test()->getJson($url, $token === null ? [] : ['Authorization' => 'Bearer '.$token]);
}

// -- POST /api/v1/customers ---------------------------------------------------

it('creates a customer', function (): void {
    [$organization, $apiKey] = customerOrganization();

    $createParams = [
        'external_id' => (string) Str::uuid(),
        'name' => 'Foo Bar Inc.',
        'firstname' => 'Foo',
        'lastname' => 'Bar',
        'customer_type' => 'company',
        'currency' => 'EUR',
        'timezone' => 'America/New_York',
        'external_salesforce_id' => 'foobar',
    ];

    $this->postJson('/api/v1/customers', ['customer' => $createParams], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($organization, $createParams): void {
        $json->where('customer.lago_id', fn ($id) => is_string($id) && $id !== '')
            ->where('customer.external_id', $createParams['external_id'])
            ->where('customer.name', $createParams['name'])
            ->where('customer.firstname', 'Foo')
            ->where('customer.lastname', 'Bar')
            ->where('customer.customer_type', 'company')
            ->where('customer.created_at', fn ($at) => is_string($at) && $at !== '')
            ->where('customer.currency', 'EUR')
            ->where('customer.external_salesforce_id', 'foobar')
            ->where('customer.account_type', 'customer')
            ->where('customer.billing_entity_code', $organization->defaultBillingEntity->code)
            ->etc();
    });
});

it('creates a customer with premium features', function (): void {
    putenv('LAGO_LICENSE=premium-license-token');
    $_ENV['LAGO_LICENSE'] = 'premium-license-token';

    try {
        [$organization, $apiKey] = customerOrganization();

        $this->postJson('/api/v1/customers', ['customer' => [
            'external_id' => (string) Str::uuid(),
            'name' => 'Foo Bar',
            'timezone' => 'America/New_York',
        ]], ['Authorization' => 'Bearer '.$apiKey->value])
            ->assertOk()
            ->assertJsonPath('customer.timezone', 'America/New_York');
    } finally {
        putenv('LAGO_LICENSE');
        unset($_ENV['LAGO_LICENSE']);
    }
});

it('creates a customer with finalize_zero_amount_invoice', function (): void {
    [, $apiKey] = customerOrganization();

    $this->postJson('/api/v1/customers', ['customer' => [
        'external_id' => (string) Str::uuid(),
        'finalize_zero_amount_invoice' => 'skip',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('customer.finalize_zero_amount_invoice', 'skip');
});

it('creates a customer with account_type partner when premium revenue_share', function (): void {
    putenv('LAGO_LICENSE=premium-license-token');
    $_ENV['LAGO_LICENSE'] = 'premium-license-token';

    try {
        [$organization, $apiKey] = customerOrganization();

        // create(:organization, premium_integrations: ["revenue_share"])
        Illuminate\Support\Facades\DB::update(
            'update organizations set premium_integrations = ARRAY[?]::varchar[] where id = ?',
            ['revenue_share', $organization->id],
        );

        $externalId = (string) Str::uuid();

        $this->postJson('/api/v1/customers', ['customer' => [
            'external_id' => $externalId,
            'name' => 'Foo Bar',
            'account_type' => 'partner',
        ]], ['Authorization' => 'Bearer '.$apiKey->value])
            ->assertOk()
            ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($externalId): void {
                $json->where('customer.external_id', $externalId)
                    ->where('customer.account_type', 'partner')
                    ->etc();
            });
    } finally {
        putenv('LAGO_LICENSE');
        unset($_ENV['LAGO_LICENSE']);
    }
});

it('creates a customer with metadata', function (): void {
    [, $apiKey] = customerOrganization();

    $this->postJson('/api/v1/customers', ['customer' => [
        'external_id' => (string) Str::uuid(),
        'name' => 'Foo Bar',
        'metadata' => [
            ['key' => 'Hello', 'value' => 'Hi', 'display_in_invoice' => true],
        ],
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
            $json->where('customer.metadata.0.key', 'Hello')
                ->where('customer.metadata.0.value', 'Hi')
                ->where('customer.metadata.0.display_in_invoice', true)
                ->etc();
        });
});

it('strips null bytes instead of returning a 500', function (): void {
    [, $apiKey] = customerOrganization();

    $this->postJson('/api/v1/customers', ['customer' => [
        'external_id' => (string) Str::uuid(),
        'name' => "Foo\0Bar",
        'firstname' => "\0",
        'lastname' => "\0",
        'currency' => 'EUR',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('customer.name', 'FooBar')
        ->assertJsonPath('customer.firstname', '')
        ->assertJsonPath('customer.lastname', '');
});

it('removes invisible characters from email', function (): void {
    [, $apiKey] = customerOrganization();

    $this->postJson('/api/v1/customers', ['customer' => [
        'external_id' => (string) Str::uuid(),
        'name' => 'Foo Bar Inc.',
        'email' => "foo\u{200C}bar@example.com",
        'firstname' => 'Foo',
        'lastname' => 'Bar',
        'customer_type' => 'company',
        'currency' => 'EUR',
        'timezone' => 'America/New_York',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('customer.email', 'foobar@example.com');
});

it('removes the full range of invisible characters from email', function (): void {
    [, $apiKey] = customerOrganization();

    $this->postJson('/api/v1/customers', ['customer' => [
        'external_id' => (string) Str::uuid(),
        'name' => 'Foo Bar Inc.',
        'email' => "foo\u{200B}\u{200C}\u{200D}\u{00A0}\u{200E}\u{200F}bar@example.com",
        'firstname' => 'Foo',
        'lastname' => 'Bar',
        'customer_type' => 'company',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('customer.email', 'foobar@example.com');
});

it('creates a customer with a unicode local-part email and preserves it', function (): void {
    [$organization, $apiKey] = customerOrganization();

    $externalId = (string) Str::uuid();

    $this->postJson('/api/v1/customers', ['customer' => [
        'external_id' => $externalId,
        'name' => 'Foo Bar Inc.',
        'email' => 'joão.silva@example.com',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('customer.email', 'joão.silva@example.com');

    // "when the customer already exists" — updates the email.
    $this->postJson('/api/v1/customers', ['customer' => [
        'external_id' => $externalId,
        'name' => 'Foo Bar Inc.',
        'email' => 'joão.silva@example.com',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('customer.email', 'joão.silva@example.com');
});

it('returns an unprocessable_entity for a malformed email', function (): void {
    [$organization, $apiKey] = customerOrganization();

    $this->postJson('/api/v1/customers', ['customer' => [
        'external_id' => (string) Str::uuid(),
        'name' => 'Foo Bar Inc.',
        'email' => 'joão.silva@',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertJsonPath('error_details.email', ['invalid_email_format']);
});

it('returns bad request when the customer param is missing', function (): void {
    [, $apiKey] = customerOrganization();

    $this->postJson('/api/v1/customers', [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertBadRequest()
        ->assertExactJson([
            'status' => 400,
            'error' => 'BadRequest: param is missing or the value is empty or invalid: customer',
        ]);
});

it('returns validation errors for invalid currency and missing external_id', function (): void {
    [$organization, $apiKey] = customerOrganization();

    $this->postJson('/api/v1/customers', ['customer' => [
        'name' => 'Foo Bar',
        'currency' => 'invalid',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertExactJson([
            'status' => 422,
            'code' => 'validation_errors',
            'error' => 'Unprocessable Entity',
            'error_details' => [
                'currency' => ['value_is_invalid'],
                'external_id' => ['value_is_mandatory'],
            ],
        ]);
});

it('creates a customer associated to the provided billing_entity', function (): void {
    [$organization, $apiKey] = customerOrganization();

    $billingEntity = BillingEntity::factory()->for($organization)->create();

    $this->postJson('/api/v1/customers', ['customer' => [
        'external_id' => (string) Str::uuid(),
        'name' => 'Foo Bar',
        'billing_entity_code' => $billingEntity->code,
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('customer.billing_entity_code', $billingEntity->code);
});

it('requires an api permission to write customers', function (): void {
    config(['lago.license' => 'premium-license-token']);

    [$organization, $apiKey] = customerOrganization();

    Illuminate\Support\Facades\DB::update(
        'update organizations set premium_integrations = ARRAY[?]::varchar[] where id = ?',
        ['api_permissions', $organization->id],
    );
    Illuminate\Support\Facades\DB::update(
        'update api_keys set permissions = ?::jsonb where id = ?',
        [json_encode(['customer' => ['read']]), $apiKey->id],
    );

    $this->postJson('/api/v1/customers', ['customer' => [
        'external_id' => (string) Str::uuid(),
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertForbidden()
        ->assertExactJson([
            'status' => 403,
            'error' => 'Forbidden',
            'code' => 'write_action_not_allowed_for_customer',
        ]);
});

// -- GET /api/v1/customers ----------------------------------------------------

it('returns all customers from the organization', function (): void {
    [$organization, $apiKey] = customerOrganization();

    Customer::factory()->count(2)->forOrganization($organization)->create();

    getWithToken('/api/v1/customers', [], $apiKey->value)
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
            $json->where('meta.total_count', 2)
                ->has('customers.0.taxes')
                ->etc();
        });
});

it('filters customers by account_type', function (): void {
    [$organization, $apiKey] = customerOrganization();

    $partner = Customer::factory()->forOrganization($organization)->create(['account_type' => 'partner']);
    Customer::factory()->count(2)->forOrganization($organization)->create();

    getWithToken('/api/v1/customers', ['account_type' => ['partner']], $apiKey->value)
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($partner): void {
            $json->count('customers', 1)->where('customers.0.lago_id', (string) $partner->id)->etc();
        });
});

it('filters customers by customer_type', function (): void {
    [$organization, $apiKey] = customerOrganization();

    $company = Customer::factory()->forOrganization($organization)->create(['customer_type' => 'company']);
    Customer::factory()->forOrganization($organization)->create(['customer_type' => 'individual']);

    getWithToken('/api/v1/customers', ['customer_type' => 'company'], $apiKey->value)
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($company): void {
            $json->count('customers', 1)->where('customers.0.lago_id', (string) $company->id)->etc();
        });
});

it('filters customers by has_customer_type', function (): void {
    [$organization, $apiKey] = customerOrganization();

    $company = Customer::factory()->forOrganization($organization)->create(['customer_type' => 'company']);
    $individual = Customer::factory()->forOrganization($organization)->create(['customer_type' => 'individual']);

    $response = getWithToken('/api/v1/customers', ['has_customer_type' => 'true'], $apiKey->value)
        ->assertOk();

    $ids = collect($response->json('customers'))->pluck('lago_id')->all();
    expect($ids)->toEqualCanonicalizing([(string) $company->id, (string) $individual->id]);
});

it('rejects customer_type when has_customer_type is false', function (): void {
    [$organization, $apiKey] = customerOrganization();

    getWithToken('/api/v1/customers', ['has_customer_type' => 'false', 'customer_type' => 'company'], $apiKey->value)
        ->assertUnprocessable()
        ->assertExactJson([
            'status' => 422,
            'code' => 'validation_errors',
            'error' => 'Unprocessable Entity',
            'error_details' => ['customer_type' => ['must be nil when has_customer_type is false']],
        ]);
});

it('filters customers by billing_entity_code', function (): void {
    [$organization, $apiKey] = customerOrganization();

    $billingEntity = BillingEntity::factory()->for($organization)->create();
    Customer::factory()->count(2)->forOrganization($organization)->create();
    // Created last, like Rails' `before { customer }` — the index is
    // ordered created_at desc, so this customer is the first row.
    $customer = Customer::factory()->forOrganization($organization)->create(['billing_entity_id' => $billingEntity->id]);

    getWithToken('/api/v1/customers', ['billing_entity_codes' => [$billingEntity->code]], $apiKey->value)
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($customer): void {
            $json->count('customers', 1)->where('customers.0.lago_id', (string) $customer->id)->etc();
        });

    // "when one of billing entities does not exist"
    getWithToken('/api/v1/customers', ['billing_entity_codes' => [$billingEntity->code, 'non_existent_code']], $apiKey->value)
        ->assertNotFound()
        ->assertJsonPath('code', 'billing_entity_not_found');

    // "with invalid billing entity codes" (scalar, not an array) — ignored.
    getWithToken('/api/v1/customers', ['billing_entity_codes' => 'invalid_code'], $apiKey->value)
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($customer): void {
            $json->count('customers', 3)->where('customers.0.lago_id', (string) $customer->id)->etc();
        });

    // "with two identical billing entity codes"
    getWithToken('/api/v1/customers', ['billing_entity_codes' => [$billingEntity->code, $billingEntity->code]], $apiKey->value)
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($customer): void {
            $json->count('customers', 1)->where('customers.0.lago_id', (string) $customer->id)->etc();
        });
});

it('filters customers by external_id', function (): void {
    [$organization, $apiKey] = customerOrganization();

    $customer = Customer::factory()->forOrganization($organization)->create();

    getWithToken('/api/v1/customers', ['external_id' => $customer->external_id], $apiKey->value)
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($customer): void {
            $json->count('customers', 1)->where('customers.0.lago_id', (string) $customer->id)->etc();
        });
});

it('filters customers by countries', function (): void {
    [$organization, $apiKey] = customerOrganization();

    $customer = Customer::factory()->forOrganization($organization)->create(['country' => 'US']);
    $customer2 = Customer::factory()->forOrganization($organization)->create(['country' => 'FR']);

    $response = getWithToken('/api/v1/customers', ['countries' => ['US', 'FR']], $apiKey->value)
        ->assertOk();

    $ids = collect($response->json('customers'))->pluck('lago_id')->all();
    expect($ids)->toEqualCanonicalizing([(string) $customer->id, (string) $customer2->id]);

    // "when filtering by invalid country"
    $response = getWithToken('/api/v1/customers', ['countries' => ['INVALID']], $apiKey->value)
        ->assertUnprocessable();

    $details = $response->json('error_details');

    expect($response->json('code'))->toBe('validation_errors')
        ->and($details['countries']['0'][0])->toMatch('/^must be one of: AD, .*XK$/');
});

it('filters customers by states', function (): void {
    [$organization, $apiKey] = customerOrganization();

    $customer = Customer::factory()->forOrganization($organization)->create(['state' => 'CA']);
    $customer2 = Customer::factory()->forOrganization($organization)->create(['state' => 'Paris']);

    $response = getWithToken('/api/v1/customers', ['states' => ['CA', 'Paris']], $apiKey->value)
        ->assertOk();

    $ids = collect($response->json('customers'))->pluck('lago_id')->all();
    expect($ids)->toEqualCanonicalizing([(string) $customer->id, (string) $customer2->id]);
});

it('filters customers by zipcodes', function (): void {
    [$organization, $apiKey] = customerOrganization();

    $customer = Customer::factory()->forOrganization($organization)->create(['zipcode' => '10115']);
    $customer2 = Customer::factory()->forOrganization($organization)->create(['zipcode' => '75001']);

    $response = getWithToken('/api/v1/customers', ['zipcodes' => ['10115', '75001']], $apiKey->value)
        ->assertOk();

    $ids = collect($response->json('customers'))->pluck('lago_id')->all();
    expect($ids)->toEqualCanonicalizing([(string) $customer->id, (string) $customer2->id]);
});

it('filters customers by currencies', function (): void {
    [$organization, $apiKey] = customerOrganization();

    $customer = Customer::factory()->forOrganization($organization)->create(['currency' => 'AED']);
    $customer2 = Customer::factory()->forOrganization($organization)->create(['currency' => 'CAD']);

    $response = getWithToken('/api/v1/customers', ['currencies' => ['AED', 'CAD']], $apiKey->value)
        ->assertOk();

    $ids = collect($response->json('customers'))->pluck('lago_id')->all();
    expect($ids)->toEqualCanonicalizing([(string) $customer->id, (string) $customer2->id]);

    // "when filtering by invalid currency"
    $response = getWithToken('/api/v1/customers', ['currencies' => ['INVALID']], $apiKey->value)
        ->assertUnprocessable();

    $details = $response->json('error_details');

    expect($details['currencies']['0'][0])->toMatch('/^must be one of: AED, AFN.*ZMW$/');
});

it('filters customers by has_tax_identification_number', function (): void {
    [$organization, $apiKey] = customerOrganization();

    $customer = Customer::factory()->forOrganization($organization)->create(['tax_identification_number' => '1234567890']);
    Customer::factory()->count(2)->forOrganization($organization)->create();

    $response = getWithToken('/api/v1/customers', ['has_tax_identification_number' => 'true'], $apiKey->value)
        ->assertOk();

    $ids = collect($response->json('customers'))->pluck('lago_id')->all();
    expect($ids)->toEqualCanonicalizing([(string) $customer->id]);

    $response = getWithToken('/api/v1/customers', ['has_tax_identification_number' => 'false'], $apiKey->value)
        ->assertOk();

    $ids = collect($response->json('customers'))->pluck('lago_id')->all();
    expect($ids)->not->toContain((string) $customer->id)->toHaveCount(2);

    $response = getWithToken('/api/v1/customers', ['has_tax_identification_number' => 'invalid'], $apiKey->value)
        ->assertUnprocessable();

    expect($response->json('error_details'))->toBe(['has_tax_identification_number' => ['must be one of: true, false']]);
});

it('filters customers by metadata', function (): void {
    [$organization, $apiKey] = customerOrganization();

    $customer = Customer::factory()->forOrganization($organization)->create();
    Customer::factory()->forOrganization($organization)->create();

    CustomerMetadata::factory()->for($customer)->create(['organization_id' => $organization->id, 'key' => 'is_synced', 'value' => 'true']);
    CustomerMetadata::factory()->for($customer)->create(['organization_id' => $organization->id, 'key' => 'last_synced_at', 'value' => '2025-01-01']);

    $response = getWithToken('/api/v1/customers', ['metadata' => ['is_synced' => 'true', 'last_synced_at' => '2025-01-01', 'first_synced_at' => '']], $apiKey->value)
        ->assertOk();

    $ids = collect($response->json('customers'))->pluck('lago_id')->all();
    expect($ids)->toEqualCanonicalizing([(string) $customer->id]);

    // "when filtering by invalid metadata"
    $response = getWithToken('/api/v1/customers', ['metadata' => ['nested' => ['deeply' => 'true'], 'is_synced' => ['true']]], $apiKey->value)
        ->assertUnprocessable();

    expect($response->json('error_details.metadata'))->toEqualCanonicalizing([
        'is_synced' => ['must be a string'],
        'nested' => ['must be a string'],
    ]);
});

it('filters customers by search_term', function (): void {
    [$organization, $apiKey] = customerOrganization();

    $customer = Customer::factory()->forOrganization($organization)->create(['name' => 'Foo Bar']);
    Customer::factory()->count(2)->forOrganization($organization)->create();

    getWithToken('/api/v1/customers', ['search_term' => 'oo b'], $apiKey->value)
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($customer): void {
            $json->count('customers', 1)->where('customers.0.lago_id', (string) $customer->id)->etc();
        });
});

it('requires an api permission to read customers on the index', function (): void {
    config(['lago.license' => 'premium-license-token']);

    [$organization, $apiKey] = customerOrganization();

    Illuminate\Support\Facades\DB::update(
        'update organizations set premium_integrations = ARRAY[?]::varchar[] where id = ?',
        ['api_permissions', $organization->id],
    );
    Illuminate\Support\Facades\DB::update(
        'update api_keys set permissions = ?::jsonb where id = ?',
        [json_encode(['customer' => ['write']]), $apiKey->id],
    );

    getWithToken('/api/v1/customers', [], $apiKey->value)
        ->assertForbidden()
        ->assertExactJson([
            'status' => 403,
            'error' => 'Forbidden',
            'code' => 'read_action_not_allowed_for_customer',
        ]);
});

// -- GET /api/v1/customers/:external_id ----------------------------------------

it('returns the customer', function (): void {
    [$organization, $apiKey] = customerOrganization();

    $customer = Customer::factory()->forOrganization($organization)->create();

    $this->getJson('/api/v1/customers/'.$customer->external_id, [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($customer): void {
        $json->where('customer.lago_id', (string) $customer->id)
            ->has('customer.taxes')
            ->etc();
    });
});

it('returns a not found error when the customer does not exist', function (): void {
    [$organization, $apiKey] = customerOrganization();

    $this->getJson('/api/v1/customers/'.(string) Str::uuid(), [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertNotFound()->assertExactJson([
        'status' => 404,
        'error' => 'Not Found',
        'code' => 'customer_not_found',
    ]);
});

it('requires an api permission to read a customer', function (): void {
    config(['lago.license' => 'premium-license-token']);

    [$organization, $apiKey] = customerOrganization();

    $customer = Customer::factory()->forOrganization($organization)->create();

    Illuminate\Support\Facades\DB::update(
        'update organizations set premium_integrations = ARRAY[?]::varchar[] where id = ?',
        ['api_permissions', $organization->id],
    );
    Illuminate\Support\Facades\DB::update(
        'update api_keys set permissions = ?::jsonb where id = ?',
        [json_encode(['customer' => ['write']]), $apiKey->id],
    );

    $this->getJson('/api/v1/customers/'.$customer->external_id, [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertForbidden()->assertJsonPath('code', 'read_action_not_allowed_for_customer');
});

// -- DELETE /api/v1/customers/:external_id --------------------------------------

it('deletes a customer', function (): void {
    [$organization, $apiKey] = customerOrganization();

    $customer = Customer::factory()->forOrganization($organization)->create();

    $before = Customer::count();

    $this->deleteJson('/api/v1/customers/'.$customer->external_id, [], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk();

    expect(Customer::count())->toBe($before - 1)
        ->and(Customer::find($customer->id))->toBeNull()
        ->and($customer->refresh()->deleted_at)->not->toBeNull();
});

it('returns the deleted customer', function (): void {
    [$organization, $apiKey] = customerOrganization();

    $customer = Customer::factory()->forOrganization($organization)->create();

    $this->deleteJson('/api/v1/customers/'.$customer->external_id, [], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()
        ->assertJsonPath('customer.lago_id', (string) $customer->id)
        ->assertJsonPath('customer.external_id', $customer->external_id);
});

it('returns a not found error when deleting a missing customer', function (): void {
    [$organization, $apiKey] = customerOrganization();

    $this->deleteJson('/api/v1/customers/'.(string) Str::uuid(), [], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertNotFound()->assertJsonPath('code', 'customer_not_found');
});

it('requires an api permission to delete a customer', function (): void {
    config(['lago.license' => 'premium-license-token']);

    [$organization, $apiKey] = customerOrganization();

    $customer = Customer::factory()->forOrganization($organization)->create();

    Illuminate\Support\Facades\DB::update(
        'update organizations set premium_integrations = ARRAY[?]::varchar[] where id = ?',
        ['api_permissions', $organization->id],
    );
    Illuminate\Support\Facades\DB::update(
        'update api_keys set permissions = ?::jsonb where id = ?',
        [json_encode(['customer' => ['read']]), $apiKey->id],
    );

    $this->deleteJson('/api/v1/customers/'.$customer->external_id, [], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertForbidden()->assertJsonPath('code', 'write_action_not_allowed_for_customer');
});

// -- v2 mirror ------------------------------------------------------------------

it('mirrors the customers endpoints at v2 with the beta header', function (): void {
    [$organization, $apiKey] = customerOrganization();

    $customer = Customer::factory()->forOrganization($organization)->create();

    $externalId = (string) Str::uuid();

    $this->postJson('/api/v2/customers', ['customer' => ['external_id' => $externalId, 'name' => 'V2 Customer']], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta')
        ->assertJsonPath('customer.name', 'V2 Customer');

    getWithToken('/api/v2/customers', [], $apiKey->value)
        ->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta')
        ->assertJsonPath('meta.total_count', 2);

    $this->getJson('/api/v2/customers/'.$customer->external_id, [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta')
        ->assertJsonPath('customer.lago_id', (string) $customer->id);

    $this->deleteJson('/api/v2/customers/'.$customer->external_id, [], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta');

    // Errors carry the beta header too.
    $this->getJson('/api/v2/customers/'.(string) Str::uuid(), [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertNotFound()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta');
});
