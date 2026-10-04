<?php

declare(strict_types=1);

uses()->group(
    'ledger:rest:POST:/api/v1/features',
    'ledger:rest:GET:/api/v1/features',
    'ledger:rest:GET:/api/v1/features/:code',
    'ledger:rest:PATCH:/api/v1/features/:code',
    'ledger:rest:PUT:/api/v1/features/:code',
    'ledger:rest:PATCH:/api/v2/features/:code',
    'ledger:rest:DELETE:/api/v1/features/:code',
    'ledger:rest:DELETE:/api/v1/features/:code/privileges/:code',
);

use App\Models\Feature;
use App\Models\Privilege;
use App\Models\Organization;

/**
 * Port of Rails' spec/requests/api/v1/features_controller_spec.rb (and the
 * nested spec/requests/api/v1/features/privileges_controller_spec.rb) — a
 * feature is keyed by its code.
 */
function featureEndpointOrganization(array $attributes = []): array
{
    $organization = Organization::factory()->create($attributes);

    return [$organization, $organization->apiKeys()->first()];
}

function featureEndpointPair(Organization $organization): array
{
    $feature = Feature::factory()->forOrganization($organization)->create([
        'code' => 'seats',
        'name' => 'Number of seats',
        'description' => 'Number of users of the account',
    ]);

    Privilege::factory()->forFeature($feature)->create([
        'code' => 'max_admins',
        'name' => '',
        'value_type' => 'integer',
    ]);
    Privilege::factory()->forFeature($feature)->create([
        'code' => 'max',
        'name' => 'Maximum',
        'value_type' => 'integer',
    ]);

    return [$feature, $feature->privileges()->get()->keyBy('code')];
}

function featureAuth(Organization $organization, $apiKey): array
{
    return ['Authorization' => 'Bearer '.$apiKey->value];
}

// -- POST /api/v1/features -------------------------------------------------------

it('creates a feature with privileges', function (): void {
    [$organization, $apiKey] = featureEndpointOrganization();

    $params = [
        'feature' => [
            'code' => 'new_feature',
            'name' => 'New Feature',
            'description' => 'A new feature',
            'privileges' => [
                ['code' => 'priv1', 'value_type' => 'string'],
                ['code' => 'priv2', 'name' => 'Maximum', 'value_type' => 'integer'],
                ['code' => 'priv3', 'value_type' => 'boolean'],
                ['code' => 'priv4', 'name' => 'SELECT', 'value_type' => 'select', 'config' => ['select_options' => ['a', 'b', 'c']]],
            ],
        ],
    ];

    $this->postJson('/api/v1/features', $params, featureAuth($organization, $apiKey))
        ->assertOk()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('feature.code', 'new_feature')
            ->where('feature.name', 'New Feature')
            ->where('feature.description', 'A new feature')
            ->count('feature.privileges', 4)
            ->etc());

    $feature = Feature::query()->where('code', 'new_feature')->sole();

    $privileges = $feature->privileges()->get()->keyBy('code');

    expect($privileges['priv1']->value_type)->toBe('string')
        ->and($privileges['priv2']->name)->toBe('Maximum')
        ->and($privileges['priv3']->value_type)->toBe('boolean')
        ->and($privileges['priv4']->value_type)->toBe('select')
        ->and($privileges['priv4']->config)->toBe(['select_options' => ['a', 'b', 'c']]);
});

it('rejects a duplicated feature code', function (): void {
    [$organization, $apiKey] = featureEndpointOrganization();
    featureEndpointPair($organization);

    $this->postJson('/api/v1/features', [
        'feature' => ['code' => 'seats', 'name' => 'New Feature', 'description' => 'A new feature'],
    ], featureAuth($organization, $apiKey))
        ->assertUnprocessable()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('code', 'validation_errors')
            ->etc());
});

it('rejects a feature with an empty code', function (): void {
    [$organization, $apiKey] = featureEndpointOrganization();

    $this->postJson('/api/v1/features', [
        'feature' => ['code' => '', 'name' => 'New Feature', 'description' => 'A new feature'],
    ], featureAuth($organization, $apiKey))
        ->assertUnprocessable()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('code', 'validation_errors')
            ->etc());
});

it('rejects an invalid privilege value type', function (): void {
    [$organization, $apiKey] = featureEndpointOrganization();

    $this->postJson('/api/v1/features', [
        'feature' => [
            'code' => 'new_feature',
            'privileges' => [['code' => 'max_admins', 'value_type' => 'invalid_type']],
        ],
    ], featureAuth($organization, $apiKey))
        ->assertUnprocessable()
        // NOTE: the envelope key is the flat "privilege.value_type" string
        // (with a dot in it), so assert the hash itself.
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('error_details', fn ($details): bool => ($details['privilege.value_type'] ?? null) === ['value_is_invalid'])
            ->etc());
});

it('creates a feature without privileges', function (): void {
    [$organization, $apiKey] = featureEndpointOrganization();

    $this->postJson('/api/v1/features', [
        'feature' => ['code' => 'new_feature', 'name' => 'New Feature', 'description' => 'A new feature'],
    ], featureAuth($organization, $apiKey))
        ->assertOk()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('feature.code', 'new_feature')
            ->where('feature.privileges', [])
            ->etc());
});

// -- GET /api/v1/features --------------------------------------------------------

it('returns a paginated list of features', function (): void {
    [$organization, $apiKey] = featureEndpointOrganization();
    [$feature] = featureEndpointPair($organization);
    Feature::factory()->forOrganization($organization)->create(['code' => 'storage', 'name' => 'Storage']);

    $this->getJson('/api/v1/features', featureAuth($organization, $apiKey))
        ->assertOk()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->count('features', 2)
            ->etc());

    $payload = $this->getJson('/api/v1/features', featureAuth($organization, $apiKey))->json('features');

    $seats = collect($payload)->firstWhere('code', 'seats');

    expect($seats['name'])->toBe('Number of seats')
        ->and($seats['description'])->toBe('Number of users of the account');

    $indexed = collect($seats['privileges'])->keyBy('code');

    expect($indexed['max_admins'])->toBe(['code' => 'max_admins', 'name' => '', 'value_type' => 'integer', 'config' => []])
        ->and($indexed['max'])->toBe(['code' => 'max', 'name' => 'Maximum', 'value_type' => 'integer', 'config' => []]);
});

it('includes pagination metadata', function (): void {
    [$organization, $apiKey] = featureEndpointOrganization();
    featureEndpointPair($organization);

    $this->getJson('/api/v1/features', featureAuth($organization, $apiKey))
        ->assertOk()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->has('meta.current_page')
            ->has('meta.total_pages')
            ->has('meta.total_count')
            ->etc());
});

it('only returns features for the current organization', function (): void {
    [$organization, $apiKey] = featureEndpointOrganization();
    featureEndpointPair($organization);

    $other = Organization::factory()->create();
    Feature::factory()->forOrganization($other)->create(['code' => 'other_feature']);

    $payload = $this->getJson('/api/v1/features', featureAuth($organization, $apiKey))
        ->assertOk()
        ->json('features');

    expect(collect($payload)->pluck('code'))->not->toContain('other_feature');
});

it('paginates features', function (): void {
    [$organization, $apiKey] = featureEndpointOrganization();
    featureEndpointPair($organization);
    Feature::factory()->forOrganization($organization)->create(['code' => 'storage', 'name' => 'Storage']);

    $this->getJson('/api/v1/features?page=1&per_page=1', featureAuth($organization, $apiKey))
        ->assertOk()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->count('features', 1)
            ->where('meta.current_page', 1)
            ->where('meta.total_pages', 2)
            ->where('meta.total_count', 2)
            ->etc());
});

it('filters features by the search term', function (): void {
    [$organization, $apiKey] = featureEndpointOrganization();
    featureEndpointPair($organization);
    Feature::factory()->forOrganization($organization)->create(['code' => 'storage', 'name' => 'Storage']);

    $payload = $this->getJson('/api/v1/features?search_term=sto', featureAuth($organization, $apiKey))
        ->assertOk()
        ->json('features');

    expect(count($payload))->toBe(1)
        ->and($payload[0]['code'])->toBe('storage');
});

// -- GET /api/v1/features/:code --------------------------------------------------

it('shows a feature', function (): void {
    [$organization, $apiKey] = featureEndpointOrganization();
    [$feature] = featureEndpointPair($organization);

    $this->getJson('/api/v1/features/'.$feature->code, featureAuth($organization, $apiKey))
        ->assertOk()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('feature.code', 'seats')
            ->where('feature.name', 'Number of seats')
            ->where('feature.description', 'Number of users of the account')
            ->count('feature.privileges', 2)
            ->etc());
});

it('answers not found for an unknown feature code', function (): void {
    [$organization, $apiKey] = featureEndpointOrganization();

    $this->getJson('/api/v1/features/non_existent', featureAuth($organization, $apiKey))
        ->assertNotFound()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('code', 'feature_not_found')
            ->etc());
});

it('answers not found for a discarded feature', function (): void {
    [$organization, $apiKey] = featureEndpointOrganization();
    [$feature] = featureEndpointPair($organization);
    $feature->delete();

    $this->getJson('/api/v1/features/'.$feature->code, featureAuth($organization, $apiKey))
        ->assertNotFound()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('code', 'feature_not_found')
            ->etc());
});

// -- PATCH /api/v1/features/:code --------------------------------------------------

it('updates the feature and privilege attributes (merge semantics)', function (): void {
    [$organization, $apiKey] = featureEndpointOrganization();
    [$feature] = featureEndpointPair($organization);

    $this->patchJson('/api/v1/features/'.$feature->code, [
        'feature' => [
            'name' => 'Updated Feature Name',
            'description' => 'Updated feature description',
            'privileges' => [['code' => 'max', 'name' => 'Max.']],
        ],
    ], featureAuth($organization, $apiKey))
        ->assertOk()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('feature.name', 'Updated Feature Name')
            ->where('feature.description', 'Updated feature description')
            ->etc());

    $privileges = $feature->privileges()->get()->keyBy('code');

    expect($privileges['max']->name)->toBe('Max.')
        // Partial update — untouched privileges are unchanged.
        ->and($privileges['max_admins']->name)->toBe('');
});

it('only updates the provided attributes', function (): void {
    [$organization, $apiKey] = featureEndpointOrganization();
    [$feature] = featureEndpointPair($organization);

    $originalName = $feature->name;

    $this->patchJson('/api/v1/features/'.$feature->code, [
        'feature' => ['description' => 'Updated feature description'],
    ], featureAuth($organization, $apiKey))
        ->assertOk()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('feature.name', $originalName)
            ->where('feature.description', 'Updated feature description')
            ->etc());
});

it('answers not found on a PATCH for an unknown feature', function (): void {
    [$organization, $apiKey] = featureEndpointOrganization();

    $this->patchJson('/api/v1/features/non_existent', [
        'feature' => ['name' => 'X'],
    ], featureAuth($organization, $apiKey))
        ->assertNotFound();
});

// -- PUT /api/v1/features/:code (same handler as PATCH) ------------------------------

it('updates via PUT', function (): void {
    [$organization, $apiKey] = featureEndpointOrganization();
    [$feature] = featureEndpointPair($organization);

    $this->putJson('/api/v1/features/'.$feature->code, [
        'feature' => ['name' => 'Put Name'],
    ], featureAuth($organization, $apiKey))
        ->assertOk()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('feature.name', 'Put Name')
            ->etc());
});

// -- DELETE /api/v1/features/:code -------------------------------------------------

it('destroys a feature', function (): void {
    [$organization, $apiKey] = featureEndpointOrganization();
    [$feature] = featureEndpointPair($organization);

    $this->deleteJson('/api/v1/features/'.$feature->code, [], featureAuth($organization, $apiKey))
        ->assertOk()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('feature.code', 'seats')
            ->etc());

    expect(Feature::query()->count())->toBe(0)
        ->and(Feature::withTrashed()->count())->toBe(1);
});

it('answers not found when destroying an unknown feature', function (): void {
    [$organization, $apiKey] = featureEndpointOrganization();

    $this->deleteJson('/api/v1/features/non_existent', [], featureAuth($organization, $apiKey))
        ->assertNotFound();
});

// -- v2 mirrors -----------------------------------------------------------------

it('mirrors the feature endpoints at v2 with the beta header', function (): void {
    [$organization, $apiKey] = featureEndpointOrganization();
    [$feature] = featureEndpointPair($organization);
    $auth = featureAuth($organization, $apiKey);

    $this->getJson('/api/v2/features', $auth)
        ->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta');

    $this->patchJson('/api/v2/features/'.$feature->code, [
        'feature' => ['name' => 'V2 Name'],
    ], $auth)
        ->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta')
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('feature.name', 'V2 Name')
            ->etc());
});

// -- DELETE /api/v1/features/:feature_code/privileges/:code ---------------------------

it('destroys a privilege and renders the feature', function (): void {
    [$organization, $apiKey] = featureEndpointOrganization();
    [$feature] = featureEndpointPair($organization);

    $this->deleteJson(
        '/api/v1/features/'.$feature->code.'/privileges/max',
        [],
        featureAuth($organization, $apiKey),
    )->assertOk()->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
        ->where('feature.code', 'seats')
        ->count('feature.privileges', 1)
        ->etc());

    expect(Privilege::query()->count())->toBe(1)
        ->and(Privilege::withTrashed()->count())->toBe(2);
});

it('answers not found for an unknown privilege', function (): void {
    [$organization, $apiKey] = featureEndpointOrganization();
    [$feature] = featureEndpointPair($organization);

    $this->deleteJson(
        '/api/v1/features/'.$feature->code.'/privileges/nonexistent',
        [],
        featureAuth($organization, $apiKey),
    )->assertNotFound();
});

it('answers not found for an unknown parent feature privilege destroy', function (): void {
    [$organization, $apiKey] = featureEndpointOrganization();

    $this->deleteJson(
        '/api/v1/features/non_existent/privileges/max',
        [],
        featureAuth($organization, $apiKey),
    )->assertNotFound();
});
