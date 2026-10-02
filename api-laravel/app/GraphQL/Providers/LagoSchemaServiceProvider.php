<?php

declare(strict_types=1);

namespace App\GraphQL\Providers;

use Closure;
use Illuminate\Support\ServiceProvider;
use App\GraphQL\Schema\LagoSchemaBuilder;
use Nuwave\Lighthouse\Events\ManipulateAST;
use Nuwave\Lighthouse\Schema\SchemaBuilder;
use Nuwave\Lighthouse\Schema\AST\DocumentAST;
use Nuwave\Lighthouse\Schema\Values\FieldValue;
use App\GraphQL\Schema\Source\FrozenSchemaSourceProvider;
use Nuwave\Lighthouse\Schema\Source\SchemaSourceProvider;
use Nuwave\Lighthouse\Support\Contracts\ProvidesResolver;
use Illuminate\Contracts\Events\Dispatcher as EventsDispatcher;
use Nuwave\Lighthouse\Support\Contracts\ProvidesSubscriptionResolver;

/**
 * Wires the Lago adaptations that let Lighthouse serve the full frozen SDL
 * (see graphql/FULL_SCHEMA_NOTES.md for the blocker analysis this closes):
 *
 * - FrozenSchemaSourceProvider: serves graphql/frozen-schema.graphql with the
 *   documented structural preprocessing applied (drop the `schema { … }`
 *   block, strip `@specifiedBy`, drop the ActionCable subscription root).
 * - LagoSchemaBuilder: builds the executable schema WITHOUT a subscription
 *   root (the billing `Subscription` object type must not be exposed as one).
 * - LagoResolverProvider: null-fallback resolvers for the not-yet-ported root
 *   fields instead of a schema-build failure, plus graphql-ruby-style field
 *   resolution (snake_case attribute lookup + type-class field methods).
 * - A delegating subscription resolver provider: Lighthouse's default throws
 *   unless its SubscriptionServiceProvider (pusher/echo infra) is registered.
 *   Rails runs subscriptions over ActionCable, which is a separate slice —
 *   meanwhile the provider hands the billing `Subscription` type's fields
 *   (the only fields that reach it) to the regular resolver provider.
 * - Strips Lighthouse's injected-but-unreferenced plumbing types
 *   (@orderBy/@softDeletes helpers) so the served type surface matches the
 *   frozen contract exactly.
 *
 * Registered AFTER Nuwave\Lighthouse\LighthouseServiceProvider (package
 * providers bind first), so these `bind()` calls win over the defaults.
 */
class LagoSchemaServiceProvider extends ServiceProvider
{
    /**
     * Types Lighthouse's OrderBy/SoftDeletes service providers inject into
     * EVERY schema's AST — the frozen contract has no such types (no
     * directives), so they are removed unless something references them.
     *
     * @var list<string>
     */
    public const LIGHTHOUSE_INJECTED_TYPES = [
        'SortOrder',
        'OrderByRelationAggregateFunction',
        'OrderByRelationWithColumnAggregateFunction',
        'OrderByClause',
        'Trashed',
    ];

    public function register(): void
    {
        $this->app->bind(SchemaSourceProvider::class, FrozenSchemaSourceProvider::class);
        $this->app->bind(ProvidesResolver::class, LagoResolverProvider::class);
        $this->app->singleton(SchemaBuilder::class, LagoSchemaBuilder::class);
        // Lighthouse routes EVERY field of a type named "Subscription" (the
        // subscription-root name) through ProvidesSubscriptionResolver — the
        // billing Subscription object type included. The real GraphQL
        // subscription root is dropped from the served schema (see
        // LagoSchemaBuilder), so the only fields reaching this provider are
        // the billing type's: delegate to the regular resolver provider
        // instead of the previous no-op null binding.
        $this->app->bind(ProvidesSubscriptionResolver::class, function (): ProvidesSubscriptionResolver {
            return new class implements ProvidesSubscriptionResolver
            {
                public function provideSubscriptionResolver(FieldValue $fieldValue): Closure
                {
                    $container = \Illuminate\Container\Container::getInstance();

                    return $container->make(ProvidesResolver::class)->provideResolver($fieldValue);
                }
            };
        });
    }

    public function boot(EventsDispatcher $dispatcher): void
    {
        $dispatcher->listen(ManipulateAST::class, function (ManipulateAST $event): void {
            $this->stripUnreferencedLighthouseTypes($event->documentAST);
        });
    }

    /**
     * Removes Lighthouse's injected plumbing types when the document never
     * references them (today: never — the frozen SDL carries no directives).
     * The containment check on the serialized AST guarantees a future
     * directive usage (e.g. `@orderBy`) keeps the type it needs.
     */
    protected function stripUnreferencedLighthouseTypes(DocumentAST $documentAST): void
    {
        $serialized = null;

        foreach (self::LIGHTHOUSE_INJECTED_TYPES as $typeName) {
            if (! isset($documentAST->types[$typeName])) {
                continue;
            }

            // Only strip when the name appears nowhere outside its own
            // definition (no field argument references it).
            $serialized ??= json_encode($this->documentWithoutInjectedTypeDefinitions($documentAST));

            if ($serialized !== false && ! str_contains((string) $serialized, $typeName)) {
                unset($documentAST->types[$typeName]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function documentWithoutInjectedTypeDefinitions(DocumentAST $documentAST): array
    {
        $types = $documentAST->types;

        foreach (self::LIGHTHOUSE_INJECTED_TYPES as $typeName) {
            unset($types[$typeName]);
        }

        return $types;
    }
}
