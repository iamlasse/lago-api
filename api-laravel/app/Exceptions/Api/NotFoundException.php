<?php

declare(strict_types=1);

namespace App\Exceptions\Api;

/**
 * Port of ApiResponses#not_found_error — the code is "<resource>_not_found".
 * The catch-all unmatched route uses resource "resource" (Rails
 * ApplicationController#not_found).
 */
class NotFoundException extends ApiException
{
    public function __construct(private readonly string $resource)
    {
        parent::__construct('Not Found');
    }

    public function statusCode(): int
    {
        return 404;
    }

    /** @return array{status: int, error: string, code: string} */
    public function body(): array
    {
        return [
            'status' => 404,
            'error' => 'Not Found',
            'code' => $this->resource.'_not_found',
        ];
    }
}
