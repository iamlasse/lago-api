<?php

declare(strict_types=1);

namespace App\GraphQL\Scalars;

use GraphQL\Error\Error;
use GraphQL\Language\AST\Node;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Language\AST\StringValueNode;

/**
 * The frozen schema's `scalar ChargeFilterValues` — an opaque pass-through for
 * charge filter values (JSON structures keyed by filter value), serialized and
 * accepted verbatim.
 */
class ChargeFilterValues extends ScalarType
{
    public ?string $description = 'Values of a charge filter, passed through as-is.';

    public function serialize($value): mixed
    {
        return $value;
    }

    public function parseValue($value): mixed
    {
        return $value;
    }

    public function parseLiteral(Node $valueNode, ?array $variables = null): mixed
    {
        if (! $valueNode instanceof StringValueNode) {
            throw new Error('ChargeFilterValues cannot represent non string value');
        }

        return json_decode($valueNode->value, true);
    }
}
