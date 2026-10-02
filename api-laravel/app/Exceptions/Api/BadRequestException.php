<?php

namespace App\Exceptions\Api;

/**
 * Port of ApiErrors#bad_request_error. Rails raises this path for
 * ActionController::ParameterMissing (see Api::BaseController#rescue_from).
 */
class BadRequestException extends ApiException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return 400;
    }

    /** @return array{status: int, error: string} */
    public function body(): array
    {
        return [
            'status' => 400,
            'error' => 'BadRequest: '.$this->getMessage(),
        ];
    }
}
