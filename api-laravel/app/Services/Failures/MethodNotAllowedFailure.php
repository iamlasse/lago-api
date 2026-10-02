<?php

declare(strict_types=1);

namespace App\Services\Failures;

class MethodNotAllowedFailure extends FailedResult
{
    public function __construct(object $result, public readonly string $code)
    {
        parent::__construct($result, $code);
    }
}
