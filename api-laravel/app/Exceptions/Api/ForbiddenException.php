<?php

declare(strict_types=1);

namespace App\Exceptions\Api;

/**
 * Port of ApiErrors#forbidden_error.
 */
class ForbiddenException extends ApiException
{
    public function __construct(string $code)
    {
        // Exception::$code (inherited) carries the Lago error code.
        $this->code = $code;

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
