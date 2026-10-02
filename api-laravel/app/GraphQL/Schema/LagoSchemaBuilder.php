<?php

declare(strict_types=1);

namespace App\GraphQL\Schema;

use GraphQL\GraphQL;
use GraphQL\Type\Schema;
use GraphQL\Type\SchemaConfig;
use GraphQL\Type\Definition\Type;
use Nuwave\Lighthouse\Schema\RootType;
use GraphQL\Type\Definition\ObjectType;
use Nuwave\Lighthouse\Schema\SchemaBuilder;
use Nuwave\Lighthouse\Schema\AST\DocumentAST;
use Nuwave\Lighthouse\Schema\Factories\DirectiveFactory;
use Nuwave\Lighthouse\Schema\AST\ExecutableTypeNodeConverter;

/**
 * Skips the subscription root registration.
 *
 * The frozen contract's subscription root is `GraphqlSubscription` (Rails
 * serves it over ActionCable — a separate slice). Lighthouse names its
 * subscription root `Subscription` by implicit naming, which collides with
 * the schema's own `Subscription` OBJECT type (the billing subscription):
 * left to the base builder, that billing type would be exposed as the
 * subscription root. Until the Laravel subscription surface is ported, no
 * subscription root is served at all — the whitelisted difference in the
 * introspection-diff test (see graphql/FULL_SCHEMA_NOTES.md).
 *
 * The build body otherwise mirrors Nuwave\Lighthouse\Schema\SchemaBuilder.
 */
class LagoSchemaBuilder extends SchemaBuilder
{
    /** Build an executable schema from an AST. */
    public function build(DocumentAST $documentAST): Schema
    {
        $config = SchemaConfig::create();

        $this->typeRegistry->setDocumentAST($documentAST);

        // Always set Query since it is required
        $query = $this->typeRegistry->get(RootType::QUERY);
        assert($query instanceof ObjectType);
        $config->setQuery($query);

        // Mutation is optional, so only add it if it is present in the
        // schema. The subscription root is intentionally NOT registered —
        // see the class docblock.
        if (isset($documentAST->types[RootType::MUTATION])) {
            $mutation = $this->typeRegistry->get(RootType::MUTATION);
            assert($mutation instanceof ObjectType);
            $config->setMutation($mutation);
        }

        // Use lazy type loading to prevent unnecessary work
        $config->setTypeLoader(
            fn (string $name): ?Type => $this->typeRegistry->search($name),
        );

        // Enables introspection to list all types in the schema
        $config->setTypes(
            /** @return array<string, Type> */
            fn (): array => $this->typeRegistry->possibleTypes(),
        );

        // Passing scalar overrides explicitly prevents the first lookup of a
        // built-in scalar from discovering them by resolving the lazy types
        // callable, which would eagerly build every type in the schema, see
        // https://github.com/nuwave/lighthouse/issues/2771.
        if (method_exists($config, 'setScalarOverrides')) { // @phpstan-ignore function.alreadyNarrowedType (backward compatibility with webonyx/graphql-php < 15.31)
            $config->setScalarOverrides($this->typeRegistry->scalarOverrides());
        }

        // There is no way to resolve directives lazily, so we convert them eagerly
        $directiveFactory = new DirectiveFactory(
            new ExecutableTypeNodeConverter($this->typeRegistry),
        );

        $directives = [];
        foreach ($documentAST->directives as $directiveDefinition) {
            $directives[] = $directiveFactory->handle($directiveDefinition);
        }

        $config->setDirectives(
            array_merge(GraphQL::getStandardDirectives(), $directives),
        );

        $config->setExtensionASTNodes($documentAST->schemaExtensions);

        return new Schema($config);
    }
}
