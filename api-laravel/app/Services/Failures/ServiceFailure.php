<?php

declare(strict_types=1);

namespace App\Services\Failures;

use Throwable;

class ServiceFailure extends FailedResult
{
    public function __construct(
        object $result,
        public readonly string $code,
        public readonly string $errorMessage,
        ?Throwable $originalError = null,
    ) {
        parent::__construct($result, $code.': '.$errorMessage, $originalError);
    }
}
