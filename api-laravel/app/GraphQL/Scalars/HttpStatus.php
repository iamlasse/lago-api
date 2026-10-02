<?php

declare(strict_types=1);

namespace App\GraphQL\Scalars;

use GraphQL\Error\Error;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\IntValueNode;
use GraphQL\Type\Definition\ScalarType;

/**
 * The frozen schema's `scalar HttpStatus` — an HTTP status code (integer
 * between 100 and 599).
 */
class HttpStatus extends ScalarType
{
    public ?string $description = 'An HTTP status code.';

    public function serialize($value): int
    {
        if (! is_numeric($value)) {
            throw new Error('HttpStatus cannot represent non numeric value: '.var_export($value, true));
        }

        $code = (int) $value;
        if ($code < 100 || $code > 599) {
            throw new Error('HttpStatus cannot represent value outside 100..599: '.$code);
        }

        return $code;
    }

    public function parseValue($value): int
    {
        return $this->serialize($value);
    }

    public function parseLiteral(Node $valueNode, ?array $variables = null): int
    {
        if (! $valueNode instanceof IntValueNode) {
            throw new Error('HttpStatus cannot represent non integer value');
        }

        return $this->serialize((int) $valueNode->value);
    }
}
