<?php

declare(strict_types=1);

namespace App\Exceptions\Api;

use RuntimeException;

/**
 * Base for exceptions that render the flat Lago error envelope. Port of the
 * render helpers in Rails' app/controllers/concerns/api_errors.rb and
 * api_responses.rb: every body carries `status` (mirroring the HTTP status),
 * `error` and — for some — `code` / `error_details`.
 */
abstract class ApiException extends RuntimeException
{
    /** HTTP status; matches the `status` key of the body. */
    abstract public function statusCode(): int;

    /** @return array<string, mixed> */
    abstract public function body(): array;
}
