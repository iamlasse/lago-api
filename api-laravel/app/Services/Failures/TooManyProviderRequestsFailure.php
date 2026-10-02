<?php

namespace App\Services\Failures;

class TooManyProviderRequestsFailure extends FailedResult
{
    public function __construct(
        object $result,
        public readonly string $providerName,
        public readonly \Throwable $error,
    ) {
        parent::__construct($result, $error->getMessage(), $error);
    }
}
