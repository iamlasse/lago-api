<?php

declare(strict_types=1);

namespace App\Services\Failures;

use Throwable;
use RuntimeException;

/**
 * Port of Rails' BaseService::FailedResult — a failure carried inside a
 * BaseResult. It is an exception so it can be raised by
 * `BaseResult::raiseIfError()` (Rails' `raise_if_error!`), but services
 * normally return it embedded in the result's `error` field.
 */
class FailedResult extends RuntimeException
{
    public function __construct(
        public readonly object $result,
        string $message,
        public readonly ?Throwable $originalError = null,
    ) {
        parent::__construct($message);
    }
}
