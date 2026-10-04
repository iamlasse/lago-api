<?php

declare(strict_types=1);

namespace App\Exceptions\Api;

/**
 * Port of the Api::V2::BaseController#invalid_request_error envelope —
 * every native v2 400 shares this body, keyed by the offending parameter:
 * {status, error: "Bad Request", code, error_details}.
 */
class InvalidRequestException extends ApiException
{
    public function __construct(
        private readonly string $errorCode,
        private readonly array|object $errorDetails = [],
    ) {
        parent::__construct('Bad Request');
    }

    public function statusCode(): int
    {
        return 400;
    }

    /** @return array{status: int, error: string, code: string, error_details: array<string, mixed>|object} */
    public function body(): array
    {
        return [
            'status' => 400,
            'error' => 'Bad Request',
            'code' => $this->errorCode,
            'error_details' => $this->errorDetails,
        ];
    }
}
