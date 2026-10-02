<?php

namespace App\Services\Failures;

class ProviderFailure extends FailedResult
{
    public function __construct(object $result, public readonly string $provider, ?\Throwable $error = null)
    {
        parent::__construct($result, '', $error);
    }
}
