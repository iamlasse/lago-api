<?php

declare(strict_types=1);

namespace App\GraphQL\Unions;

use GraphQL\Type\Definition\Type;
use Nuwave\Lighthouse\Schema\SchemaBuilder;
use App\GraphQL\Interfaces\AbstractLagoTypeResolver;

/**
 * Base for union type resolvers that need to look a member type up from the
 * executable schema (there is no `GraphQL` facade alias registered in this
 * app).
 */
abstract class AbstractLagoUnionTypeResolver extends AbstractLagoTypeResolver
{
    public function __construct(protected readonly SchemaBuilder $schemaBuilder) {}

    /** Resolves a member type of the served schema by name. */
    protected function schemaType(string $name): Type
    {
        /** @var Type */
        return $this->schemaBuilder->schema()->getType($name);
    }
}
