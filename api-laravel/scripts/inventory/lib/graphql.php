<?php

// Generator: GraphQL operations inventory from the Rails repo's schema.json
// (a stored introspection result). One row per Query/Mutation/Subscription
// field. Row ids: gql:query:customer, gql:mutation:createCustomer.

if (! function_exists('inv_gql_type_string')) {
    /**
     * Flattens an introspection type reference into SDL notation.
     *
     * @param  array<string, mixed>  $type
     */
    function inv_gql_type_string(array $type): string
    {
        return match ($type['kind'] ?? null) {
            'NON_NULL' => inv_gql_type_string($type['ofType'] ?? []).'!',
            'LIST' => '['.inv_gql_type_string($type['ofType'] ?? []).']',
            default => (string) ($type['name'] ?? 'UNKNOWN'),
        };
    }
}

if (! function_exists('inv_gen_graphql')) {
    /**
     * @return array<int, array<string, mixed>> rows sorted by id
     *
     * @throws RuntimeException when schema.json is missing or malformed
     */
    function inv_gen_graphql(string $railsPath): array
    {
        inv_assert_rails_path($railsPath, 'schema.json');

        $raw = file_get_contents($railsPath.'/schema.json');
        $decoded = json_decode((string) $raw, true);

        $schema = $decoded['data']['__schema'] ?? null;

        if (! is_array($schema)) {
            throw new RuntimeException("[$railsPath/schema.json] is not an introspection result (missing data.__schema).");
        }

        $roots = [];
        foreach (['queryType' => 'query', 'mutationType' => 'mutation', 'subscriptionType' => 'subscription'] as $key => $kind) {
            if (! empty($schema[$key]['name'])) {
                $roots[$schema[$key]['name']] = $kind;
            }
        }

        $rows = [];

        foreach ($schema['types'] ?? [] as $type) {
            $kind = $roots[$type['name'] ?? null] ?? null;

            if ($kind === null || ! isset($type['fields'])) {
                continue;
            }

            foreach ($type['fields'] as $field) {
                $rows[] = [
                    'id' => "gql:{$kind}:{$field['name']}",
                    'kind' => $kind,
                    'name' => $field['name'],
                    'args' => array_map(static fn (array $arg): array => [
                        'name' => $arg['name'],
                        'type' => inv_gql_type_string($arg['type'] ?? []),
                    ], $field['args'] ?? []),
                    'return' => inv_truncate(inv_gql_type_string($field['type'] ?? [])),
                    'deprecated' => (bool) ($field['isDeprecated'] ?? false),
                    'source' => 'schema.json',
                ];
            }
        }

        return inv_sort_rows($rows);
    }
}
