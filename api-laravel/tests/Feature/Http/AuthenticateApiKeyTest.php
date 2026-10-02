<?php

use App\Models\ApiKey;
use App\Models\Organization;
use App\Support\CurrentContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Port of Rails' spec/requests/api/base_controller_spec.rb against the
 * `lago.auth` middleware, plus the v2 beta header and permission scenarios.
 */
->group('ledger:rest:api.base_controller');

function createOrganizationWithApiKey(array $orgAttributes = [], array $apiKeyAttributes = []): array
{
    $organization = Organization::create(array_merge(['name' => 'Auth Org'], $orgAttributes));

    $apiKey = ApiKey::create(array_merge([
        'organization_id' => $organization->id,
        'value' => (string) Str::uuid(),
        'permissions' => [],
    ], $apiKeyAttributes));

    return [$organization, $apiKey];
}

it('sets the context source to api and records the api key id', function () {
    [, $apiKey] = createOrganizationWithApiKey();

    $this->getJson('/api/v1/placeholder', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk();

    expect(CurrentContext::$source)->toBe('api')
        ->and(CurrentContext::$apiKeyId)->toBe($apiKey->id)
        ->and(CurrentContext::$organization->id)->toBe($apiKey->organization_id);
});

it('returns success for a valid authorization header', function () {
    // Rails samples a plain and an :expiring (still valid) key — both succeed.
    [$organization, $apiKey] = createOrganizationWithApiKey();
    [, $expiringApiKey] = createOrganizationWithApiKey(['name' => 'Auth Org 2'], [
        'expires_at' => now()->addMinutes(5),
    ]);

    $this->getJson('/api/v1/placeholder', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk();

    $this->getJson('/api/v1/placeholder', ['Authorization' => 'Bearer '.$expiringApiKey->value])
        ->assertOk();
});

it('returns 401 with the Unauthorized envelope for a missing authorization header', function () {
    $this->getJson('/api/v1/placeholder')
        ->assertUnauthorized()
        ->assertExactJson(['status' => 401, 'error' => 'Unauthorized']);
});

it('returns 401 for an unknown token', function () {
    $this->getJson('/api/v1/placeholder', ['Authorization' => 'Bearer '.Str::uuid()])
        ->assertUnauthorized()
        ->assertExactJson(['status' => 401, 'error' => 'Unauthorized']);
});

it('returns 401 for an expired api key', function () {
    // Rails: create(:api_key, :expired) — the active default_scope hides it.
    [$organization, $apiKey] = createOrganizationWithApiKey([], [
        'expires_at' => now()->subMinute(),
    ]);

    $this->getJson('/api/v1/placeholder', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnauthorized();
});

it('tracks api key usage in the cache on trackable endpoints', function () {
    [, $apiKey] = createOrganizationWithApiKey();

    $this->getJson('/api/v1/placeholder', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk();

    expect(Cache::get("api_key_last_used_{$apiKey->id}"))->toBe(now()->toIso8601String());
});

it('does not parse the auth scheme, mirroring Rails split-on-whitespace', function () {
    [, $apiKey] = createOrganizationWithApiKey();

    // Rails: headers["Authorization"]&.split(" ")&.second — no "Bearer" check.
    $this->getJson('/api/v1/placeholder', ['Authorization' => 'Token '.$apiKey->value])
        ->assertOk();
});

it('allows every resource when the organization has no premium api_permissions', function () {
    [, $apiKey] = createOrganizationWithApiKey([], [
        // Empty permissions map would deny everything if enforced.
        'permissions' => [],
    ]);

    $this->getJson('/api/v1/placeholder', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk();
});

it('returns 403 with the mode/resource code when permissions are enforced and deny the action', function () {
    config(['lago.license' => 'premium-license-token']);

    [$organization, $apiKey] = createOrganizationWithApiKey(
        ['name' => 'Perms Org', 'premium_integrations' => ['api_permissions']],
        ['permissions' => ['organization' => ['write']]],
    );

    // GET -> mode "read" -> denied.
    $this->getJson('/api/v1/placeholder', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertForbidden()
        ->assertExactJson([
            'status' => 403,
            'error' => 'Forbidden',
            'code' => 'read_action_not_allowed_for_organization',
        ]);

    // POST -> mode "write" -> allowed.
    $this->postJson('/api/v1/placeholder', ['input' => ['value' => 'x']], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk();
});

it('serves the beta header on every v2 response, errors included', function () {
    [, $apiKey] = createOrganizationWithApiKey();

    $this->getJson('/api/v2/placeholder', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta');

    $this->getJson('/api/v2/placeholder')
        ->assertUnauthorized()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta')
        ->assertExactJson(['status' => 401, 'error' => 'Unauthorized']);
});

it('serves the same handlers at v1 and v2', function () {
    [$organization, $apiKey] = createOrganizationWithApiKey();

    foreach (['v1', 'v2'] as $version) {
        $this->postJson("/api/{$version}/placeholder", ['input' => 1], [
            'Authorization' => 'Bearer '.$apiKey->value,
        ])->assertOk()->assertJson(['placeholder' => true]);
    }
});
