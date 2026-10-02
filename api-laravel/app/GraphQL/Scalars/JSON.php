<?php

declare(strict_types=1);

namespace App\GraphQL\Scalars;

use MLL\GraphQLScalars\MixedScalar;

/**
 * Pass-through JSON scalar (mirrors the frozen schema's `scalar JSON`): any
 * JSON-serializable value is accepted and returned as-is; literals are JSON
 * strings and validated as such.
 */
class JSON extends MixedScalar
{
    public ?string $description = 'Arbitrary JSON payload, passed through as-is.';
}
