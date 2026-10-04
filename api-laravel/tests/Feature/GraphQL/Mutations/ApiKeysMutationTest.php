<?php

declare(strict_types=1);

require_once __DIR__.'/../GraphQLHelpers.php';
require_once __DIR__.'/../AuthPlumbingTest.php';
require_once __DIR__.'/../Resolvers/ApiKeysResolverTest.php';

use App\Models\ApiKey;
use App\Models\Organization;

/**
 * Ports of Rails' spec/graphql/mutations/api_keys/{create,update,rotate,
 * destroy}_spec.rb — the API key mutations over the frozen SDL.
 *
 * Ledger rows: gql:mutation:createApiKey, gql:mutation:updateApiKey,
 * gql:mutation:rotateApiKey, gql:mutation:destroyApiKey.
 */

/**
 * BaseService#premium reads config('lago.license').
 */
function gqlActAsPremiumOrganization(Organization $organization): void
{
    config(['lago.license' => 'test-license']);
    $organization->premium_integrations = ['api_permissions'];
    $organization->save();
}

function gqlReleasePremiumLicense(): void {}

it('refuses to create an api key without a premium license', function (): void {
    [$organization, $user] = gqlApiKeysSetup();

    $response = gqlPost(
        'mutation { createApiKey(input: {name: "Free Key"}) { id name value } }',
        [],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.createApiKey'))->toBeNull()
        ->and($response->json('errors.0.message'))->toBe('forbidden')
        ->and($response->json('errors.0.extensions'))->toBe([
            'status' => 403,
            'code' => 'feature_unavailable',
        ]);
    expect(ApiKey::query()->where('organization_id', $organization->id)->count())->toBe(0);
})->group('ledger:gql:mutation:createApiKey');

it('creates an api key with a premium license', function (): void {
    [$organization, $user] = gqlApiKeysSetup();
    gqlActAsPremiumOrganization($organization);

    try {
        $response = gqlPost(
            'mutation { createApiKey(input: {name: "Deploy Key"}) { id name value permissions } }',
            [],
            gqlAuthHeaders($user, $organization->id),
        );

        $payload = $response->json('data.createApiKey');

        expect($payload['name'])->toBe('Deploy Key')
            ->and($payload['value'])->not->toBeEmpty();

        $apiKey = ApiKey::query()->find($payload['id']);
        expect($apiKey->organization_id)->toBe($organization->id);

        // JSON key order is not significant.
        $sorted = static fn (array $permissions): array => collect($permissions)
            ->sortKeys()->map(fn ($modes): array => collect($modes)->sort()->values()->all())->all();

        expect($sorted($payload['permissions']))->toBe($sorted(ApiKey::defaultPermissions()));
    } finally {
        gqlReleasePremiumLicense();
    }
})->group('ledger:gql:mutation:createApiKey');

it('refuses permissions without the api_permissions premium integration', function (): void {
    [$organization, $user] = gqlApiKeysSetup();
    // Premium license, but the organization lacks the integration.
    config(['lago.license' => 'test-license']);

    try {
        $response = gqlPost(
            'mutation { createApiKey(input: {name: "Scoped", permissions: "{\\"customer\\": [\\"read\\"]}"}) { id } }',
            [],
            gqlAuthHeaders($user, $organization->id),
        );

        expect($response->json('errors.0.extensions'))->toBe([
            'status' => 403,
            'code' => 'premium_integration_missing',
        ]);
    } finally {
        gqlReleasePremiumLicense();
    }
})->group('ledger:gql:mutation:createApiKey');

it('updates an api key name and permissions', function (): void {
    [$organization, $user] = gqlApiKeysSetup();
    gqlActAsPremiumOrganization($organization);

    $apiKey = gqlMakeApiKey($organization);

    try {
        $response = gqlPost(
            'mutation($input: UpdateApiKeyInput!) { updateApiKey(input: $input) { id name permissions } }',
            ['input' => ['id' => $apiKey->id, 'name' => 'Renamed', 'permissions' => ['customer' => ['read', 'write']]]],
            gqlAuthHeaders($user, $organization->id),
        );

        $payload = $response->json('data.updateApiKey');

        expect($payload['id'])->toBe($apiKey->id)
            ->and($payload['name'])->toBe('Renamed')
            ->and($payload['permissions'])->toBe(['customer' => ['read', 'write']]);

        expect($apiKey->refresh()->name)->toBe('Renamed');
    } finally {
        gqlReleasePremiumLicense();
    }
})->group('ledger:gql:mutation:updateApiKey');

it('returns not_found when updating an api key of another organization', function (): void {
    [$organization, $user] = gqlApiKeysSetup();
    gqlActAsPremiumOrganization($organization);

    $foreign = gqlMakeApiKey(gqlCreateOrganization('Elsewhere'));

    try {
        $response = gqlPost(
            'mutation($input: UpdateApiKeyInput!) { updateApiKey(input: $input) { id } }',
            ['input' => ['id' => $foreign->id, 'name' => 'Hijacked']],
            gqlAuthHeaders($user, $organization->id),
        );

        expect($response->json('errors.0.message'))->toBe('Resource not found')
            ->and($response->json('errors.0.extensions.status'))->toBe(404);
        expect($foreign->refresh()->name)->not->toBe('Hijacked');
    } finally {
        gqlReleasePremiumLicense();
    }
})->group('ledger:gql:mutation:updateApiKey');

it('rotates an api key: creates a replacement and expires the original', function (): void {
    [$organization, $user] = gqlApiKeysSetup();

    $apiKey = gqlMakeApiKey($organization);

    $response = gqlPost(
        'mutation($input: RotateApiKeyInput!) { rotateApiKey(input: $input) { id value } }',
        ['input' => ['id' => $apiKey->id, 'name' => 'Rotated Key']],
        gqlAuthHeaders($user, $organization->id),
    );

    $payload = $response->json('data.rotateApiKey');

    expect($payload['id'])->not->toBe($apiKey->id)
        ->and($payload['value'])->not->toBe($apiKey->value);

    expect($apiKey->refresh()->expires_at)->not->toBeNull()
        ->and($apiKey->expires_at->lessThanOrEqualTo(now()))->toBeTrue();

    $replacement = ApiKey::query()->find($payload['id']);
    expect($replacement->organization_id)->toBe($organization->id)
        ->and($replacement->name)->toBe('Rotated Key')
        ->and($replacement->expires_at)->toBeNull();

    // The expired original is gone from the active scope. Travel past the
    // whole second of its expiry: the scope compares with a second-precision
    // binding while the column keeps microseconds.
    $this->travel(1)->seconds();
    $list = gqlPost(
        'query { apiKeys { collection { id } metadata { totalCount } } }',
        [],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($list->json('data.apiKeys.metadata.totalCount'))->toBe(1)
        ->and($list->json('data.apiKeys.collection.0.id'))->toBe($replacement->id);
})->group('ledger:gql:mutation:rotateApiKey');

it('refuses rotating with a provided expires_at without a premium license', function (): void {
    [$organization, $user] = gqlApiKeysSetup();

    $apiKey = gqlMakeApiKey($organization);

    $response = gqlPost(
        'mutation($input: RotateApiKeyInput!) { rotateApiKey(input: $input) { id } }',
        ['input' => ['id' => $apiKey->id, 'expiresAt' => '2030-01-01T00:00:00Z']],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('errors.0.extensions'))->toBe([
        'status' => 403,
        'code' => 'cannot_rotate_with_provided_date',
    ]);
})->group('ledger:gql:mutation:rotateApiKey');

it('destroys an api key by expiring it, keeping the organization logged in', function (): void {
    [$organization, $user] = gqlApiKeysSetup();

    $apiKey = gqlMakeApiKey($organization);
    $survivor = gqlMakeApiKey($organization);

    $response = gqlPost(
        'mutation($input: DestroyApiKeyInput!) { destroyApiKey(input: $input) { id expiresAt } }',
        ['input' => ['id' => $apiKey->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.destroyApiKey.id'))->toBe($apiKey->id)
        ->and($response->json('data.destroyApiKey.expiresAt'))->not->toBeNull();

    expect($apiKey->refresh()->expires_at)->not->toBeNull()
        ->and($survivor->refresh()->expires_at)->toBeNull();
})->group('ledger:gql:mutation:destroyApiKey');

it('refuses to destroy the last non expiring api key', function (): void {
    [$organization, $user] = gqlApiKeysSetup();

    $apiKey = gqlMakeApiKey($organization);

    $response = gqlPost(
        'mutation($input: DestroyApiKeyInput!) { destroyApiKey(input: $input) { id } }',
        ['input' => ['id' => $apiKey->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.destroyApiKey'))->toBeNull()
        ->and($response->json('errors.0.message'))->toBe('Unprocessable Entity')
        ->and($response->json('errors.0.extensions'))->toBe([
            'status' => 422,
            'code' => 'unprocessable_entity',
            'details' => ['base' => ['last_non_expiring_api_key']],
        ]);

    expect($apiKey->refresh()->expires_at)->toBeNull();
})->group('ledger:gql:mutation:destroyApiKey');
