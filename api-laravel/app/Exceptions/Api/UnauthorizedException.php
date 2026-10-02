<?php

namespace App\Exceptions\Api;

/**
 * Port of ApiErrors#unauthorized_error (default message "Unauthorized").
 */
class UnauthorizedException extends ApiException
{
    public function __construct(string $message = 'Unauthorized')
    {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return 401;
    }

    /** @return array{status: int, error: string} */
    public function body(): array
    {
        return [
            'status' => 401,
            'error' => $this->getMessage(),
        ];
    }
}
