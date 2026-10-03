<?php

declare(strict_types=1);

uses()->group(
    'ledger:rest:POST:/api/v1/taxes',
    'ledger:rest:GET:/api/v1/taxes',
    'ledger:rest:GET:/api/v1/taxes/:code',
    'ledger:rest:PUT:/api/v1/taxes/:code',
    'ledger:rest:DELETE:/api/v1/taxes/:code',
);

use App\Models\Tax;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' spec/requests/api/v1/taxes_controller_spec.rb — a tax is
 * keyed by its code (Rails: resources :taxes, param: :code).
 */
function taxEndpointOrganization(array $attributes = []): array
{
    $organization = Organization::factory()->create($attributes);

    return [$organization, $organization->apiKeys()->first()];
}

/**
 * Rails' :premium spec tag — License.premium? is true while a license key
 * is configured.
 */
function withTaxPremiumLicense(callable $scenario): void
{
    putenv('LAGO_LICENSE=premium-license-token');
    $_ENV['LAGO_LICENSE'] = 'premium-license-token';

    try {
        $scenario();
    } finally {
        putenv('LAGO_LICENSE');
        unset($_ENV['LAGO_LICENSE']);
    }
}

/**
 * Rails trait :applied_to_billing_entity — the billing_entities_taxes join
 * row on the default billing entity.
 */
function appliedToDefaultBillingEntity(Tax $tax, Organization $organization): void
{
    DB::table('billing_entities_taxes')->insert([
        'id' => (string) Illuminate\Support\Str::uuid(),
        'billing_entity_id' => $organization->defaultBillingEntity->id,
        'tax_id' => $tax->id,
        'organization_id' => $organization->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// -- POST /api/v1/taxes ---------------------------------------------------------

it('creates a tax', function (): void {
    [$organization, $apiKey] = taxEndpointOrganization();

    $createParams = [
        'name' => 'tax',
        'code' => 'tax_code',
        'rate' => 20.0,
        'description' => 'tax_description',
        'applied_to_organization' => false,
    ];

    $this->postJson('/api/v1/taxes', ['tax' => $createParams], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($createParams): void {
        $json->where('tax.code', $createParams['code'])
            ->where('tax.name', $createParams['name'])
            // PHP json-encodes the float 20.0 as 20 (the serializers do not
            // use JSON_PRESERVE_ZERO_FRACTION; Rails emits 20.0).
            ->where('tax.rate', 20)
            ->where('tax.description', 'tax_description')
            ->where('tax.applied_to_organization', false)
            ->where('tax.applied_to_billing_entities_codes', [])
            ->has('tax.lago_id')
            ->has('tax.created_at')
            ->etc();
    });

    expect(Tax::count())->toBe(1);
});

// -- PUT /api/v1/taxes/:code ------------------------------------------------------

it('updates a tax', function (): void {
    [$organization, $apiKey] = taxEndpointOrganization();

    $tax = Tax::factory()->create(['organization_id' => $organization->id]);

    $updateParams = [
        'code' => 'code_updated',
        'name' => 'name_updated',
        'rate' => 15.0,
        'applied_to_organization' => false,
    ];

    $this->putJson('/api/v1/taxes/'.$tax->code, ['tax' => $updateParams], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($tax): void {
        $json->where('tax.lago_id', $tax->id)
            ->where('tax.code', 'code_updated')
            ->where('tax.name', 'name_updated')
            // See the create test — PHP json-encodes the float 15.0 as 15.
            ->where('tax.rate', 15)
            ->where('tax.applied_to_organization', false)
            ->etc();
    });
});

it('returns not_found when the updated tax does not exist', function (): void {
    [$organization, $apiKey] = taxEndpointOrganization();

    $this->putJson('/api/v1/taxes/'.Illuminate\Support\Str::uuid(), ['tax' => [
        'name' => 'tax',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound()
        ->assertExactJson([
            'status' => 404,
            'error' => 'Not Found',
            'code' => 'tax_not_found',
        ]);
});

it('rejects a tax code that already exists in the organization', function (): void {
    [$organization, $apiKey] = taxEndpointOrganization();

    $tax = Tax::factory()->create(['organization_id' => $organization->id]);
    $tax2 = Tax::factory()->create(['organization_id' => $organization->id]);

    $this->putJson('/api/v1/taxes/'.$tax->code, ['tax' => [
        'code' => $tax2->code,
        'name' => 'name_updated',
        'rate' => 15.0,
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertExactJson([
            'status' => 422,
            'error' => 'Unprocessable Entity',
            'code' => 'validation_errors',
            'error_details' => ['code' => ['value_already_exist']],
        ]);
});

// -- GET /api/v1/taxes/:code -------------------------------------------------------

it('returns a tax', function (): void {
    [$organization, $apiKey] = taxEndpointOrganization();

    $tax = Tax::factory()->create(['organization_id' => $organization->id]);

    $this->getJson('/api/v1/taxes/'.$tax->code, ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($tax): void {
            $json->where('tax.lago_id', $tax->id)
                ->where('tax.code', $tax->code)
                ->etc();
        });
});

it('returns applied_to_organization true with the billing entity code', function (): void {
    [$organization, $apiKey] = taxEndpointOrganization();

    $tax = Tax::factory()->create(['organization_id' => $organization->id]);
    appliedToDefaultBillingEntity($tax, $organization);

    $this->getJson('/api/v1/taxes/'.$tax->code, ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('tax.applied_to_organization', true)
        ->assertJsonPath('tax.applied_to_billing_entities_codes', [$organization->defaultBillingEntity->code]);
});

it('returns not_found when the tax does not exist', function (): void {
    [$organization, $apiKey] = taxEndpointOrganization();

    $this->getJson('/api/v1/taxes/'.Illuminate\Support\Str::uuid(), [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertNotFound();
});

// -- DELETE /api/v1/taxes/:code -----------------------------------------------------

it('deletes a tax and its join rows', function (): void {
    [$organization, $apiKey] = taxEndpointOrganization();

    $tax = Tax::factory()->create(['organization_id' => $organization->id]);
    appliedToDefaultBillingEntity($tax, $organization);

    $this->deleteJson('/api/v1/taxes/'.$tax->code, headers: [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk();

    expect(Tax::count())->toBe(0)
        ->and(Tax::withTrashed()->count())->toBe(1)
        // The customers_taxes join rows are removed by the destroy service.
        ->and(DB::table('customers_taxes')->where('tax_id', $tax->id)->count())->toBe(0);
});

it('returns the deleted tax', function (): void {
    [$organization, $apiKey] = taxEndpointOrganization();

    $tax = Tax::factory()->create(['organization_id' => $organization->id]);

    $this->deleteJson('/api/v1/taxes/'.$tax->code, headers: [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($tax): void {
        $json->where('tax.lago_id', $tax->id)
            ->where('tax.code', $tax->code)
            ->etc();
    });
});

it('returns not_found when deleting a tax that does not exist', function (): void {
    [$organization, $apiKey] = taxEndpointOrganization();

    $this->deleteJson('/api/v1/taxes/'.Illuminate\Support\Str::uuid(), headers: [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertNotFound();
});

// -- GET /api/v1/taxes ---------------------------------------------------------------

it('returns the organization taxes', function (): void {
    [$organization, $apiKey] = taxEndpointOrganization();

    $tax = Tax::factory()->create(['organization_id' => $organization->id]);

    $this->getJson('/api/v1/taxes?page=1&per_page=1', [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($tax): void {
        $json->where('taxes.0.lago_id', $tax->id)
            ->where('taxes.0.code', $tax->code)
            ->count('taxes', 1)
            ->etc();
    });
});

it('returns applied_to_organization true on the index for a tax on the default billing entity', function (): void {
    [$organization, $apiKey] = taxEndpointOrganization();

    $tax = Tax::factory()->create(['organization_id' => $organization->id]);
    appliedToDefaultBillingEntity($tax, $organization);

    $this->getJson('/api/v1/taxes', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($organization): void {
            $json->where('taxes.0.applied_to_organization', true)
                ->where('taxes.0.applied_to_billing_entities_codes', [$organization->defaultBillingEntity->code])
                ->etc();
        });
});

it('returns taxes with pagination meta', function (): void {
    [$organization, $apiKey] = taxEndpointOrganization();

    Tax::factory()->count(2)->create(['organization_id' => $organization->id]);

    $this->getJson('/api/v1/taxes?page=1&per_page=1', [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
        $json->count('taxes', 1)
            ->where('meta.current_page', 1)
            ->where('meta.next_page', 2)
            ->where('meta.prev_page', null)
            ->where('meta.total_pages', 2)
            ->where('meta.total_count', 2)
            ->etc();
    });
});

// -- v2 mirror -------------------------------------------------------------------------

it('mirrors the tax endpoints at v2 with the beta header', function (): void {
    [$organization, $apiKey] = taxEndpointOrganization();

    $this->postJson('/api/v2/taxes', ['tax' => [
        'name' => 'tax',
        'code' => 'tax_code',
        'rate' => 20.0,
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta')
        ->assertJsonPath('tax.code', 'tax_code');

    $this->getJson('/api/v2/taxes/tax_code', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta');

    $this->getJson('/api/v2/taxes/not_a_tax', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta');
});

// -- api permissions ---------------------------------------------------------------------

it('requires an api permission to write taxes', function (): void {
    withTaxPremiumLicense(function (): void {
        config(['lago.license' => 'premium-license-token']);

        [$organization, $apiKey] = taxEndpointOrganization();

        DB::update(
            'update organizations set premium_integrations = ARRAY[?]::varchar[] where id = ?',
            ['api_permissions', $organization->id],
        );
        DB::update(
            'update api_keys set permissions = ?::jsonb where id = ?',
            [json_encode(['tax' => ['read']]), $apiKey->id],
        );

        $this->postJson('/api/v1/taxes', ['tax' => [
            'name' => 'tax',
            'code' => 'tax_code',
            'rate' => 20.0,
        ]], ['Authorization' => 'Bearer '.$apiKey->value])
            ->assertForbidden()
            ->assertExactJson([
                'status' => 403,
                'error' => 'Forbidden',
                'code' => 'write_action_not_allowed_for_tax',
            ]);
    });
});

it('allows the write when the api permission grants it', function (): void {
    withTaxPremiumLicense(function (): void {
        config(['lago.license' => 'premium-license-token']);

        [$organization, $apiKey] = taxEndpointOrganization();

        DB::update(
            'update organizations set premium_integrations = ARRAY[?]::varchar[] where id = ?',
            ['api_permissions', $organization->id],
        );
        DB::update(
            'update api_keys set permissions = ?::jsonb where id = ?',
            [json_encode(['tax' => ['write']]), $apiKey->id],
        );

        $this->postJson('/api/v1/taxes', ['tax' => [
            'name' => 'tax',
            'code' => 'tax_code',
            'rate' => 20.0,
        ]], ['Authorization' => 'Bearer '.$apiKey->value])
            ->assertOk()
            ->assertJsonPath('tax.code', 'tax_code');
    });
});
