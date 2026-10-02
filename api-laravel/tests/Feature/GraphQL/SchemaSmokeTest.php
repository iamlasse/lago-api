<?php

declare(strict_types=1);

require_once __DIR__.'/GraphQLHelpers.php';

/**
 * Introspection smoke + security limits (ports of the LagoApiSchema
 * max_depth 15 / max_complexity 350 settings and the GraphqlController
 * 15,000-char query cap).
 */
const INTROSPECTION_QUERY = <<<'GQL'
query IntrospectionQuery {
    __schema {
        queryType {
            name
        }
        mutationType {
            name
        }
        types {
            name
            kind
        }
    }
}
GQL;

it('serves introspection including the ported operations', function () {
    $response = gqlPost(INTROSPECTION_QUERY);

    $response->assertOk();

    $schema = $response->json('data.__schema');

    expect($schema['queryType']['name'])->toBe('Query')
        ->and($schema['mutationType']['name'])->toBe('Mutation');

    $typeNames = collect($schema['types'])->pluck('name')->all();

    expect($typeNames)->toContain('User')
        ->and($typeNames)->toContain('Membership')
        ->and($typeNames)->toContain('Organization')
        ->and($typeNames)->toContain('LoginUser')
        ->and($typeNames)->toContain('CurrentVersion')
        ->and($typeNames)->toContain('BigInt')
        ->and($typeNames)->toContain('ISO8601DateTime')
        ->and($typeNames)->toContain('ISO8601Date')
        ->and($typeNames)->toContain('JSON')
        ->and($typeNames)->toContain('ObfuscatedString')
        ->and($typeNames)->toContain('ChargeFilterValues')
        ->and($typeNames)->toContain('HttpStatus');
});

it('exposes the loginUser mutation and currentUser query on the schema', function () {
    $response = gqlPost(<<<'GQL'
    query {
        __schema {
            queryType {
                fields {
                    name
                }
            }
            mutationType {
                fields {
                    name
                }
            }
        }
    }
    GQL);

    $queryFields = collect($response->json('data.__schema.queryType.fields'))->pluck('name')->all();
    $mutationFields = collect($response->json('data.__schema.mutationType.fields'))->pluck('name')->all();

    expect($queryFields)->toContain('currentUser')
        ->and($queryFields)->toContain('currentVersion')
        ->and($mutationFields)->toContain('loginUser');
});

it('rejects queries deeper than 15 levels', function () {
    // user → memberships → organization → … nesting beyond max_depth 15
    $level = 'id';
    foreach (range(1, 10) as $ignored) {
        $level = "memberships { user { $level } }";
    }

    $response = gqlPost("query { currentUser { $level } }");

    $errors = $response->json('errors');

    expect($errors)->not->toBeEmpty()
        ->and(json_encode($errors))->toContain('depth');
});

it('rejects queries exceeding the complexity budget of 350', function () {
    // Each aliased branch costs ~11 complexity points (graphql-php counts 1 +
    // children per field); 40 aliases ≈ 440 > 350, depth stays 11 < 15.
    $branch = 'memberships { user { memberships { user { memberships { user { memberships { user { memberships { user { id } } } } } } } } } }';
    $aliases = implode(' ', collect(range(1, 40))->map(fn ($i): string => "m{$i}: {$branch}")->all());

    $response = gqlPost("query { currentUser { {$aliases} } }");

    $errors = $response->json('errors');

    expect($errors)->not->toBeEmpty()
        ->and(json_encode($errors))->toContain('complex');
});

it('rejects queries longer than 15,000 characters with query_is_too_large', function () {
    $hugeQuery = str_repeat('#', 15_001);

    $response = gqlPost($hugeQuery);

    // Rails: render_graphql_error — HTTP 200 with a controller-level envelope.
    $response->assertOk();

    expect($response->json('data'))->toBe([])
        ->and($response->json('errors.0.message'))->toBe('Max query length is 15000, your query is 15001')
        ->and($response->json('errors.0.extensions'))->toBe([
            'status' => 413,
            'code' => 'query_is_too_large',
        ]);
});
