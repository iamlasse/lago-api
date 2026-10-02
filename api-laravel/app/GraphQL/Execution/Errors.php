<?php

declare(strict_types=1);

namespace App\GraphQL\Execution;

use Str;
use App\GraphQL\Exceptions\ExecutionError;

/**
 * Port of Rails' ExecutionErrorResponder concern
 * (app/graphql/concerns/execution_error_responder.rb).
 *
 * Every helper builds an App\GraphQL\Exceptions\ExecutionError carrying
 * `{status, code, details?}` extensions; details keys are lowerCamelized
 * exactly like Rails' `transform_keys { |k| k.to_s.camelize(:lower) }`.
 */
final class Errors
{
    /**
     * @param  array<string, mixed>|null  $details
     */
    public static function executionError(
        string $error = 'Internal Error',
        int|string $status = 422,
        string $code = 'internal_error',
        ?array $details = null,
    ): ExecutionError {
        if ($details !== null) {
            $details = self::lowerCamelizeKeys($details);
        }

        return new ExecutionError($error, $status, $code, $details);
    }

    public static function notFoundError(string $resource): ExecutionError
    {
        return self::executionError(
            error: 'Resource not found',
            status: 404,
            code: 'not_found',
            details: [$resource => ['not_found']],
        );
    }

    public static function notAllowedError(string $code): ExecutionError
    {
        return self::executionError(
            error: 'Method Not Allowed',
            status: 405,
            code: $code,
        );
    }

    public static function forbiddenError(string $code): ExecutionError
    {
        return self::executionError(
            error: 'forbidden',
            status: 403,
            code: $code,
        );
    }

    /**
     * @param  array<string, mixed>  $messages
     */
    public static function validationError(array $messages): ExecutionError
    {
        return self::executionError(
            error: 'Unprocessable Entity',
            status: 422,
            code: 'unprocessable_entity',
            details: $messages,
        );
    }

    /**
     * @param  list<string>  $messages
     */
    public static function thirdPartyFailure(array $messages): ExecutionError
    {
        return self::executionError(
            error: 'Unprocessable Entity',
            status: 422,
            code: 'third_party_error',
            details: ['error' => $messages],
        );
    }

    /**
     * Rails' `camelize(:lower)` on a details tree: `external_customer_id`
     * becomes `externalCustomerId`. Applied recursively to hashes (arrays of
     * messages are left untouched, mirroring `transform_keys`).
     *
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    public static function lowerCamelizeKeys(array $details): array
    {
        $result = [];

        foreach ($details as $key => $value) {
            $result[Str::camel((string) $key)] = is_array($value)
                ? self::lowerCamelizeKeys($value)
                : $value;
        }

        return $result;
    }
}
