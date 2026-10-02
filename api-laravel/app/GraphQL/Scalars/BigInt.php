<?php

declare(strict_types=1);

namespace App\GraphQL\Scalars;

use GraphQL\Error\Error;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\IntValueNode;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Language\AST\StringValueNode;

/**
 * GraphQL-Ruby's `GraphQL::Types::BigInt` — integers serialized as strings in
 * JSON (money amounts exceed PHP's int range), accepted as int or numeric
 * string on input.
 */
class BigInt extends ScalarType
{
    public ?string $description = 'Represents non-fractional signed whole numeric values.';

    public function serialize($value): string
    {
        if (! is_numeric($value)) {
            throw new Error('BigInt cannot represent non numeric value: '.var_export($value, true));
        }

        return (string) $value;
    }

    public function parseValue($value): string
    {
        if (! is_numeric($value)) {
            throw new Error('BigInt cannot represent non numeric value: '.var_export($value, true));
        }

        return (string) $value;
    }

    public function parseLiteral(Node $valueNode, ?array $variables = null): string
    {
        if (! $valueNode instanceof StringValueNode && ! $valueNode instanceof IntValueNode) {
            throw new Error('BigInt cannot represent non numeric value: '.$valueNode->kind);
        }

        return (string) $valueNode->value;
    }
}
