<?php

declare(strict_types=1);

namespace App\Exceptions\Api;

/**
 * Port of ActionController::ParameterMissing as rendered by
 * Api::BaseController's rescue_from -> ApiErrors#bad_request_error.
 * Message text matches Rails' ActiveSupport wording.
 */
class ParameterMissingException extends ApiException
{
    public function __construct(private readonly string $param)
    {
        parent::__construct(sprintf(
            'param is missing or the value is empty or invalid: %s',
            $param,
        ));
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
