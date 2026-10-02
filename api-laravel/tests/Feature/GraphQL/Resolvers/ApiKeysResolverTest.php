<?php

declare(strict_types=1);

require_once __DIR__.'/../GraphQLHelpers.php';
require_once __DIR__.'/../AuthPlumbingTest.php';

use App\Models\ApiKey;
use App\Models\Organization;

/**
 * Port of Rails' spec/graphql/resolvers/{api_keys_resolver_spec.rb,
 * api_key_resolver_spec.rb}: the SanitizedApiKeyCollection surface (masked
 * values, kaminari metadata) and the single `apiKey` query.
 *
 * Ledger rows: gql:query:apiKeys, gql:query:apiKey.
 */
function gqlApiKeysSetup(): array
{
    $organization = gqlCreateOrganization();
    $user = gqlCreateUser();
    gqlCreateMembership($user, $organization);

    return [$organization, $user];
}

function gqlMakeApiKey(Organization $organization, array $attributes = []): ApiKey
{
    return ApiKey::factory()->create(['organization_id' => $organization->id, ...$attributes]);
}

it('lists the organization api keys with sanitized values and kaminari metadata', function (): void {
    [$organization, $user] = gqlApiKeysSetup();

    $first = gqlMakeApiKey($organization, ['created_at' => now()->subDay()]);
    $second = gqlMakeApiKey($organization);

    $response = gqlPost(<<<'GQL'
    query {
        apiKeys(limit: 1, page: 2) {
            collection { id name value createdAt }
            metadata { currentPage limitValue totalPages totalCount }
        }
    }
    GQL, [], gqlAuthHeaders($user, $organization->id));

    $payload = $response->json('data.apiKeys');

    // created_at ASC ordering → the older key is on page 1, page 2 holds the
    // newer one; the value is masked `••••••••` + last 3 characters.
    expect($payload['metadata'])->toBe([
        'currentPage' => 2,
        'limitValue' => 1,
        'totalPages' => 2,
        'totalCount' => 2,
    ])
        ->and($payload['collection'])->toHaveCount(1)
        ->and($payload['collection'][0]['id'])->toBe($second->id)
        ->and($payload['collection'][0]['value'])->toBe('••••••••'.mb_substr($second->value, -3));
})->group('ledger:gql:query:apiKeys');

it('hides expired api keys like the Rails default_scope :active', function (): void {
    [$organization, $user] = gqlApiKeysSetup();

    gqlMakeApiKey($organization, ['expires_at' => now()->subHour()]);

    $response = gqlPost(
        'query { apiKeys { collection { id } metadata { totalCount } } }',
        [],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.apiKeys.metadata.totalCount'))->toBe(0)
        ->and($response->json('data.apiKeys.collection'))->toBe([]);
})->group('ledger:gql:query:apiKeys');

it('returns a single api key and the not_found envelope for unknown ids', function (): void {
    [$organization, $user] = gqlApiKeysSetup();

    $apiKey = gqlMakeApiKey($organization);

    $found = gqlPost(
        "query { apiKey(id: \"{$apiKey->id}\") { id name value } }",
        [],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($found->json('data.apiKey.id'))->toBe($apiKey->id)
        ->and($found->json('data.apiKey.value'))->toBe($apiKey->value);

    $missing = gqlPost(
        'query { apiKey(id: "00000000-0000-0000-0000-000000000000") { id } }',
        [],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($missing->json('data.apiKey'))->toBeNull()
        ->and($missing->json('errors.0.message'))->toBe('Resource not found')
        ->and($missing->json('errors.0.extensions'))->toBe([
            'status' => 404,
            'code' => 'not_found',
            'details' => ['apiKey' => ['not_found']],
        ]);
})->group('ledger:gql:query:apiKey');
