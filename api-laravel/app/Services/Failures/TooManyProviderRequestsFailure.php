<?php

declare(strict_types=1);

namespace App\Services\Failures;

use Throwable;

class TooManyProviderRequestsFailure extends FailedResult
{
    public function __construct(
        object $result,
        public readonly string $providerName,
        public readonly Throwable $error,
    ) {
        parent::__construct($result, $error->getMessage(), $error);
    }
}
