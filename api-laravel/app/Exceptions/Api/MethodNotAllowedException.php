<?php

declare(strict_types=1);

namespace App\Exceptions\Api;

/**
 * Port of ApiErrors#method_not_allowed_error.
 */
class MethodNotAllowedException extends ApiException
{
    public function __construct(private readonly string $code)
    {
        parent::__construct('Method Not Allowed');
    }

    public function statusCode(): int
    {
        return 405;
    }

    /** @return array{status: int, error: string, code: string} */
    public function body(): array
    {
        return [
            'status' => 405,
            'error' => 'Method Not Allowed',
            'code' => $this->code,
        ];
    }
}
