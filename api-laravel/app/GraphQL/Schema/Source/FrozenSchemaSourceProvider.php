<?php

declare(strict_types=1);

namespace App\GraphQL\Schema\Source;

use Nuwave\Lighthouse\Schema\Source\SchemaSourceProvider;

/**
 * Serves the frozen contract (graphql/frozen-schema.graphql — never edited)
 * to Lighthouse after a minimal, documented preprocessing step.
 *
 * Lighthouse v6 cannot consume the verbatim SDL for three reasons (see
 * graphql/FULL_SCHEMA_NOTES.md):
 *
 * 1. it throws on the explicit `schema { … }` block
 *    ("Unknown definition type: SchemaDefinitionNode");
 * 2. it has no handler for `@specifiedBy` and a shim directive would collide
 *    with graphql-php's built-in one ("defined multiple times");
 * 3. the subscription root `GraphqlSubscription` must go: Lighthouse
 *    recognizes root types by implicit naming only, and renaming it to
 *    `Subscription` collides with the schema's own `Subscription` object
 *    type (the billing subscription). Rails serves subscriptions over
 *    ActionCable — that surface is a separate slice, so the root (and its
 *    single field) is dropped with the schema block.
 *
 * The transformations are purely structural — no type, field, argument or
 * enum value is touched besides the removed subscription root — so the
 * served schema stays a faithful port of the frozen contract. The
 * introspection-diff test whitelists exactly that documented difference.
 */
class FrozenSchemaSourceProvider implements SchemaSourceProvider
{
    /** @var string|null */
    private static $cached;

    public function getSchemaString(): string
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        $source = (string) file_get_contents(config('lighthouse.frozen_schema_path', base_path('graphql/frozen-schema.graphql')));

        $source = $this->dropSchemaDefinitionBlock($source);
        $source = $this->stripSpecifiedBy($source);
        $source = $this->dropSubscriptionRoot($source);

        return self::$cached = $source;
    }

    /**
     * Drops the leading `schema { … }` block. `query: Query` and
     * `mutation: Mutation` match Lighthouse's implicit names; the
     * `subscription: GraphqlSubscription` line is handled by
     * dropSubscriptionRoot below.
     */
    private function dropSchemaDefinitionBlock(string $source): string
    {
        return (string) preg_replace('/^schema \{[^}]*\}\s*/m', '', $source, 1);
    }

    /**
     * Strips the two informational `@specifiedBy(url: …)` applications on the
     * ISO8601 scalars (frozen-schema.graphql:7547, 7552).
     */
    private function stripSpecifiedBy(string $source): string
    {
        return (string) preg_replace('/\s*@specifiedBy\(url:\s*"[^"]*"\)/', '', $source);
    }

    /**
     * Drops the `type GraphqlSubscription { … }` root type declaration — the
     * Lighthouse equivalent (subscriptions over a broadcaster) is a separate
     * slice, and renaming the type would collide with the billing
     * `Subscription` object type.
     */
    private function dropSubscriptionRoot(string $source): string
    {
        return (string) preg_replace(
            '/^type GraphqlSubscription \{[^}]*\}\s*/m',
            '',
            $source,
            1,
        );
    }
}
