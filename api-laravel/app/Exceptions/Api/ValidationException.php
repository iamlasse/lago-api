<?php

declare(strict_types=1);

namespace App\Exceptions\Api;

/**
 * Port of ApiErrors#validation_errors — 422 with the `validation_errors`
 * code and the message hash under `error_details`.
 */
class ValidationException extends ApiException
{
    /**
     * Rails' `validation_errors(errors:)` renders whatever `errors` value
     * the service failure carried (a hash, a bare string, an index-keyed
     * object) under `error_details`.
     *
     * @param  array<string, mixed>|object|string  $errors
     */
    public function __construct(private readonly array|object|string $errors)
    {
        parent::__construct('Unprocessable Entity');
    }

    public function statusCode(): int
    {
        return 422;
    }

    /** @return array{status: int, error: string, code: string, error_details: array<string, mixed>|object|string} */
    public function body(): array
    {
        return [
            'status' => 422,
            'error' => 'Unprocessable Entity',
            'code' => 'validation_errors',
            'error_details' => $this->errors,
        ];
    }
}
