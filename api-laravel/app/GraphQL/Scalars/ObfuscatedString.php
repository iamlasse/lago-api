<?php

declare(strict_types=1);

namespace App\GraphQL\Scalars;

use GraphQL\Error\Error;
use GraphQL\Language\AST\Node;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Language\AST\StringValueNode;

/**
 * Port of Rails' Types::ObfuscatedStringType
 * (app/graphql/types/obfuscated_string_type.rb): sensitive values are masked
 * on the way out as `••••••••…xyz` (last 3 characters kept).
 */
class ObfuscatedString extends ScalarType
{
    public ?string $description = 'A string whose content is obfuscated in responses.';

    public function serialize($value): ?string
    {
        if ($value === null) {
            return null;
        }

        return str_repeat('•', 8).'…'.mb_substr((string) $value, -3);
    }

    public function parseValue($value): string
    {
        if (! is_string($value)) {
            throw new Error('ObfuscatedString cannot represent non string value');
        }

        return $value;
    }

    public function parseLiteral(Node $valueNode, ?array $variables = null): string
    {
        if (! $valueNode instanceof StringValueNode) {
            throw new Error('ObfuscatedString cannot represent non string value');
        }

        return $valueNode->value;
    }
}
