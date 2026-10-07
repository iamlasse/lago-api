<?php

declare(strict_types=1);

namespace App\GraphQL\Interfaces;

use App\GraphQL\Execution\Errors;
use GraphQL\Type\Definition\Type;

/**
 * Shared body of the frozen SDL's interface type resolvers.
 *
 * Nothing produces a value of these interfaces yet (the resolvers that
 * return them are not ported), so — per the agreed stub semantics —
 * resolving the concrete type throws instead of guessing, and the error
 * carries the standard Lago extensions shape.
 */
abstract class AbstractLagoTypeResolver
{
    public function __invoke(mixed $root): Type
    {
        // Not implemented yet — see graphql/FULL_SCHEMA_NOTES.md.
        throw Errors::executionError(
            error: 'This part of the schema is not implemented yet',
            status: 500,
            code: 'not_implemented',
        );
    }

    /** Looks the concrete object type up in the schema by its SDL name. */
    protected function type(string $name): Type
    {
        return resolve(\Nuwave\Lighthouse\Schema\TypeRegistry::class)->get($name);
    }
}
