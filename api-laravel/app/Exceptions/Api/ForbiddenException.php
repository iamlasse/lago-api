<?php

namespace App\Exceptions\Api;

/**
 * Port of ApiErrors#forbidden_error.
 */
class ForbiddenException extends ApiException
{
    public function __construct(private readonly string $code)
    {
        parent::__construct('Forbidden');
    }

    public function statusCode(): int
    {
        return 403;
    }

    /** @return array{status: int, error: string, code: string} */
    public function body(): array
    {
        return [
            'status' => 403,
            'error' => 'Forbidden',
            'code' => $this->code,
        ];
    }
}
