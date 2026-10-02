<?php

declare(strict_types=1);

require_once __DIR__.'/GraphQLHelpers.php';

use GraphQL\GraphQL;
use Illuminate\Support\Arr;

/**
 * The acceptance gate for serving the full frozen contract: the LIVE schema's
 * type surface and root operation field names must match the Rails API's
 * introspection dump (schema.json), distilled into
 * tests/fixtures/GraphQL/rails-schema-surface.json.
 *
 * The diff runs against the schema Lighthouse actually serves (built through
 * the container, so FrozenSchemaSourceProvider's preprocessing applies). The
 * introspection bypasses the HTTP layer's complexity cap (350) — a full
 * introspection query is inherently more complex, and Rails' own endpoint has
 * the same property.
 *
 * Documented, whitelisted difference: the subscription ROOT type
 * (`GraphqlSubscription`, served over ActionCable in Rails) is not served —
 * Lighthouse recognizes root types by implicit naming, and a rename would
 * collide with the schema's own `Subscription` object type (the billing
 * subscription). The Laravel subscription surface is a separate slice; the
 * OBJECT type `Subscription` and every other type/field are compared as
 * usual. See graphql/FULL_SCHEMA_NOTES.md.
 */
const WHITELISTED_MISSING_TYPES = ['GraphqlSubscription'];

it('serves every type of the frozen schema', function (): void {
    $surface = railsSchemaSurface();
    $served = servedSchemaSurface();

    $expectedTypes = array_values(array_diff($surface['typeNames'], WHITELISTED_MISSING_TYPES));

    $missing = array_values(array_diff($expectedTypes, $served['typeNames']));
    $extra = array_values(array_diff($served['typeNames'], $surface['typeNames']));

    expect($missing)->toBe([], 'Types the frozen schema has but the served schema is missing')
        ->and($extra)->toBe([], 'Types the served schema has that are not in the frozen schema');
});

it('serves every root query field of the frozen schema', function (): void {
    $surface = railsSchemaSurface();
    $served = servedSchemaSurface();

    expect(array_values(array_diff($surface['queryFields'], $served['queryFields'])))
        ->toBe([], 'Query fields missing from the served schema')
        ->and(array_values(array_diff($served['queryFields'], $surface['queryFields'])))
        ->toBe([], 'Query fields the served schema adds over the frozen schema');
});

it('serves every root mutation field of the frozen schema', function (): void {
    $surface = railsSchemaSurface();
    $served = servedSchemaSurface();

    expect(array_values(array_diff($surface['mutationFields'], $served['mutationFields'])))
        ->toBe([], 'Mutation fields missing from the served schema')
        ->and(array_values(array_diff($served['mutationFields'], $surface['mutationFields'])))
        ->toBe([], 'Mutation fields the served schema adds over the frozen schema');
});

it('drops the subscription root with the documented preprocessing', function (): void {
    $served = servedSchemaSurface();

    // The single subscription field (`aiConversationStreamed`) comes back
    // with the ActionCable slice; until then no subscription root is served.
    expect($served['subscriptionType'])->toBeNull();
});

it('keeps every implemented operation served and named after the frozen schema', function (): void {
    // Regression guard for the operations ported so far — these must never
    // silently disappear from the served schema.
    $served = servedSchemaSurface();

    foreach (['currentUser', 'currentVersion', 'organization', 'customer', 'customers', 'apiKey', 'apiKeys'] as $field) {
        expect($served['queryFields'])->toContain($field);
    }

    foreach (['loginUser', 'updateOrganization', 'createCustomer', 'updateCustomer', 'destroyCustomer', 'createApiKey', 'updateApiKey', 'rotateApiKey', 'destroyApiKey'] as $field) {
        expect($served['mutationFields'])->toContain($field);
    }
});

/**
 * The distilled Rails surface (generated from the Rails repo's schema.json).
 *
 * @return array{typeNames: list<string>, queryFields: list<string>, mutationFields: list<string>, subscriptionFields: list<string>}
 */
function railsSchemaSurface(): array
{
    /** @var array{typeNames: list<string>, queryFields: list<string>, mutationFields: list<string>, subscriptionFields: list<string>} $fixture */
    return json_decode(
        (string) file_get_contents(__DIR__.'/../../fixtures/GraphQL/rails-schema-surface.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

/**
 * The live surface of the schema Lighthouse serves, via full introspection.
 *
 * @return array{typeNames: list<string>, queryFields: list<string>, mutationFields: list<string>, subscriptionFields: list<string>, subscriptionType: ?string}
 */
function servedSchemaSurface(): array
{
    $schema = app(Nuwave\Lighthouse\Schema\SchemaBuilder::class)->schema();

    $result = GraphQL::executeQuery($schema, <<<'GQL'
    query {
      __schema {
        queryType { name fields { name } }
        mutationType { name fields { name } }
        subscriptionType { name }
        types { name }
      }
    }
    GQL);

    $data = $result->toArray();
    expect(Arr::get($data, 'errors'))->toBeNull();

    $schemaData = $data['data']['__schema'];

    return [
        'typeNames' => array_values(array_filter(
            array_map(fn (array $type): string => $type['name'], $schemaData['types']),
            fn (string $name): bool => ! str_starts_with($name, '__'),
        )),
        'queryFields' => array_map(fn (array $field): string => $field['name'], $schemaData['queryType']['fields'] ?? []),
        'mutationFields' => array_map(fn (array $field): string => $field['name'], $schemaData['mutationType']['fields'] ?? []),
        'subscriptionFields' => [],
        'subscriptionType' => $schemaData['subscriptionType']['name'] ?? null,
    ];
}
