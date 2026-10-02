<?php

declare(strict_types=1);

namespace App\GraphQL\Scalars;

use Exception;
use Carbon\Carbon;
use DateTimeInterface;
use GraphQL\Error\Error;
use Carbon\CarbonInterface;
use GraphQL\Language\AST\Node;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Language\AST\StringValueNode;

/**
 * Port of GraphQL-Ruby's `GraphQL::Types::ISO8601Date` as used by Lago: dates
 * leave the API in UTC with a trailing `Z` (e.g. `2024-05-04Z`... rendered as
 * `2024-05-04T00:00:00Z` at midnight UTC).
 */
class ISO8601Date extends ScalarType
{
    public ?string $description = 'An ISO 8601-encoded date, serialized in UTC.';

    public function serialize($value): string
    {
        if ($value instanceof CarbonInterface || $value instanceof DateTimeInterface) {
            return $value->clone()->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z');
        }

        if (is_string($value)) {
            try {
                return Carbon::parse($value)->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z');
            } catch (Exception $e) {
                throw new Error('ISO8601Date cannot serialize value: '.$value);
            }
        }

        throw new Error('ISO8601Date cannot serialize value of type '.get_debug_type($value));
    }

    public function parseValue($value): Carbon
    {
        if (! is_string($value)) {
            throw new Error('ISO8601Date cannot represent non string value');
        }

        try {
            return Carbon::parse($value)->startOfDay()->setTimezone('UTC');
        } catch (Exception $e) {
            throw new Error('ISO8601Date cannot represent value: '.$value);
        }
    }

    public function parseLiteral(Node $valueNode, ?array $variables = null): Carbon
    {
        if (! $valueNode instanceof StringValueNode) {
            throw new Error('ISO8601Date cannot represent non string value');
        }

        return $this->parseValue($valueNode->value);
    }
}
