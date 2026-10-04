<?php

declare(strict_types=1);

uses()->group(
    'ledger:rest:POST:/api/v1/add_ons',
    'ledger:rest:GET:/api/v1/add_ons',
    'ledger:rest:GET:/api/v1/add_ons/:code',
    'ledger:rest:PUT:/api/v1/add_ons/:code',
    'ledger:rest:PATCH:/api/v2/add_ons/:code',
    'ledger:rest:DELETE:/api/v1/add_ons/:code',
);

use App\Models\Tax;
use App\Models\AddOn;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' spec/requests/api/v1/add_ons_controller_spec.rb — an
 * add-on is keyed by its code (Rails: resources :add_ons, param: :code).
 */
function addOnEndpointOrganization(array $attributes = []): array
{
    $organization = Organization::factory()->create($attributes);

    return [$organization, $organization->apiKeys()->first()];
}

// -- POST /api/v1/add_ons -------------------------------------------------------

it('creates an add-on', function (): void {
    [$organization, $apiKey] = addOnEndpointOrganization();

    $tax = Tax::factory()->create(['organization_id' => $organization->id]);

    $createParams = [
        'name' => 'add_on1',
        'invoice_display_name' => 'Addon 1 invoice name',
        'code' => 'add_on1_code',
        'amount_cents' => 123,
        'amount_currency' => 'EUR',
        'description' => 'description',
        'tax_codes' => [$tax->code],
    ];

    $this->postJson('/api/v1/add_ons', ['add_on' => $createParams], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($createParams, $tax): void {
        $json->where('add_on.code', $createParams['code'])
            ->where('add_on.name', $createParams['name'])
            ->where('add_on.invoice_display_name', $createParams['invoice_display_name'])
            ->where('add_on.amount_cents', 123)
            ->where('add_on.amount_currency', 'EUR')
            ->where('add_on.taxes.0.code', $tax->code)
            ->has('add_on.lago_id')
            ->has('add_on.created_at')
            ->etc();
    });

    expect(AddOn::count())->toBe(1)
        ->and(DB::table('add_ons_taxes')->count())->toBe(1);
});

it('creates an add-on without taxes when no tax_codes are sent', function (): void {
    [$organization, $apiKey] = addOnEndpointOrganization();

    $this->postJson('/api/v1/add_ons', ['add_on' => [
        'name' => 'add_on1',
        'code' => 'add_on1_code',
        'amount_cents' => 123,
        'amount_currency' => 'EUR',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('add_on.code', 'add_on1_code');

    expect(DB::table('add_ons_taxes')->count())->toBe(0);
});

// -- PUT /api/v1/add_ons/:code ----------------------------------------------------

it('updates an add-on', function (): void {
    [$organization, $apiKey] = addOnEndpointOrganization();

    $tax = Tax::factory()->create(['organization_id' => $organization->id]);
    $tax2 = Tax::factory()->create(['organization_id' => $organization->id]);

    $addOn = AddOn::factory()->create(['organization_id' => $organization->id]);
    DB::table('add_ons_taxes')->insert([
        'id' => (string) Illuminate\Support\Str::uuid(),
        'add_on_id' => $addOn->id,
        'tax_id' => $tax->id,
        'organization_id' => $organization->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $updateParams = [
        'name' => 'add_on1',
        'invoice_display_name' => 'Addon 1 updated invoice name',
        'code' => 'add_on_code',
        'amount_cents' => 123,
        'amount_currency' => 'EUR',
        'description' => 'description',
        'tax_codes' => [$tax2->code],
    ];

    $this->putJson('/api/v1/add_ons/'.$addOn->code, ['add_on' => $updateParams], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($addOn, $updateParams, $tax2): void {
        $json->where('add_on.lago_id', $addOn->id)
            ->where('add_on.code', $updateParams['code'])
            ->where('add_on.invoice_display_name', $updateParams['invoice_display_name'])
            ->where('add_on.taxes.0.code', $tax2->code)
            ->etc();
    });

    // The old tax fell out of the list — the sync removed its join row.
    expect(DB::table('add_ons_taxes')->where('tax_id', $tax->id)->exists())->toBeFalse();
});

it('returns not_found when the updated add-on does not exist', function (): void {
    [$organization, $apiKey] = addOnEndpointOrganization();

    $this->putJson('/api/v1/add_ons/'.Illuminate\Support\Str::uuid(), ['add_on' => [
        'name' => 'add_on1',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound()
        ->assertExactJson([
            'status' => 404,
            'error' => 'Not Found',
            'code' => 'add_on_not_found',
        ]);
});

it('rejects an add-on code that already exists in the organization', function (): void {
    [$organization, $apiKey] = addOnEndpointOrganization();

    $addOn = AddOn::factory()->create(['organization_id' => $organization->id]);
    $addOn2 = AddOn::factory()->create(['organization_id' => $organization->id]);

    $this->putJson('/api/v1/add_ons/'.$addOn->code, ['add_on' => [
        'name' => 'add_on1',
        'code' => $addOn2->code,
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertJsonPath('error_details.code.0', 'value_already_exist');
});

// -- GET /api/v1/add_ons/:code ------------------------------------------------------

it('returns an add-on', function (): void {
    [$organization, $apiKey] = addOnEndpointOrganization();

    $addOn = AddOn::factory()->create(['organization_id' => $organization->id]);

    $this->getJson('/api/v1/add_ons/'.$addOn->code, [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($addOn): void {
        $json->where('add_on.lago_id', $addOn->id)
            ->where('add_on.code', $addOn->code)
            ->where('add_on.invoice_display_name', $addOn->invoice_display_name)
            ->etc();
    });
});

it('returns not_found when the add-on does not exist', function (): void {
    [$organization, $apiKey] = addOnEndpointOrganization();

    $this->getJson('/api/v1/add_ons/'.Illuminate\Support\Str::uuid(), [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertNotFound()
        ->assertExactJson([
            'status' => 404,
            'error' => 'Not Found',
            'code' => 'add_on_not_found',
        ]);
});

// -- DELETE /api/v1/add_ons/:code ---------------------------------------------------

it('deletes an add-on', function (): void {
    [$organization, $apiKey] = addOnEndpointOrganization();

    $addOn = AddOn::factory()->create(['organization_id' => $organization->id]);

    $this->deleteJson('/api/v1/add_ons/'.$addOn->code, [], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJsonPath('add_on.lago_id', $addOn->id);

    // Rails: expect { subject }.to change(AddOn, :count).by(-1) — the
    // discard takes the kept scope down by one.
    expect(AddOn::count())->toBe(0)
        // The row is discarded, not destroyed (the serializer still renders
        // it in the response, like Rails' discard).
        ->and($addOn->fresh()->trashed())->toBeTrue();
});

it('returns not_found when the deleted add-on does not exist', function (): void {
    [$organization, $apiKey] = addOnEndpointOrganization();

    $this->deleteJson('/api/v1/add_ons/'.Illuminate\Support\Str::uuid(), [], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertNotFound();
});

// -- GET /api/v1/add_ons -------------------------------------------------------------

it('returns add-ons', function (): void {
    [$organization, $apiKey] = addOnEndpointOrganization();

    $tax = Tax::factory()->create(['organization_id' => $organization->id]);
    $addOn = AddOn::factory()->create(['organization_id' => $organization->id]);

    DB::table('add_ons_taxes')->insert([
        'id' => (string) Illuminate\Support\Str::uuid(),
        'add_on_id' => $addOn->id,
        'tax_id' => $tax->id,
        'organization_id' => $organization->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->getJson('/api/v1/add_ons', [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($addOn, $tax): void {
        $json->where('add_ons.0.lago_id', $addOn->id)
            ->where('add_ons.0.code', $addOn->code)
            ->where('add_ons.0.invoice_display_name', $addOn->invoice_display_name)
            ->where('add_ons.0.taxes.0.code', $tax->code)
            ->etc();
    });
});

it('returns add-ons with correct pagination metadata', function (): void {
    [$organization, $apiKey] = addOnEndpointOrganization();

    AddOn::factory()->count(2)->create(['organization_id' => $organization->id]);

    $this->getJson('/api/v1/add_ons?page=1&per_page=1', [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
        $json->count('add_ons', 1)
            ->where('meta.current_page', 1)
            ->where('meta.next_page', 2)
            ->where('meta.prev_page', null)
            ->where('meta.total_pages', 2)
            ->where('meta.total_count', 2)
            ->etc();
    });
});

// -- api permissions -----------------------------------------------------------------

it('requires an api permission to write add-ons', function (): void {
    // Permissions are only enforced when the (premium) api_permissions
    // integration is enabled — License.premium? first.
    config(['lago.license' => 'premium-license-token']);

    [$organization, $apiKey] = addOnEndpointOrganization();

    DB::update(
        'update organizations set premium_integrations = ARRAY[?]::varchar[] where id = ?',
        ['api_permissions', $organization->id],
    );
    DB::update(
        'update api_keys set permissions = ?::jsonb where id = ?',
        [json_encode(['add_on' => ['read']]), $apiKey->id],
    );

    $this->postJson('/api/v1/add_ons', ['add_on' => [
        'name' => 'add_on1',
        'code' => 'add_on1_code',
        'amount_cents' => 123,
        'amount_currency' => 'EUR',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertForbidden();
});

it('allows the write when the api permission grants it', function (): void {
    config(['lago.license' => 'premium-license-token']);

    [$organization, $apiKey] = addOnEndpointOrganization();

    DB::update(
        'update organizations set premium_integrations = ARRAY[?]::varchar[] where id = ?',
        ['api_permissions', $organization->id],
    );
    DB::update(
        'update api_keys set permissions = ?::jsonb where id = ?',
        [json_encode(['add_on' => ['write']]), $apiKey->id],
    );

    $this->postJson('/api/v1/add_ons', ['add_on' => [
        'name' => 'add_on1',
        'code' => 'add_on1_code',
        'amount_cents' => 123,
        'amount_currency' => 'EUR',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('add_on.code', 'add_on1_code');
});
