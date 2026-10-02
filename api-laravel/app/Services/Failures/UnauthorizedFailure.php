<?php

namespace App\Services\Failures;

class UnauthorizedFailure extends FailedResult
{
    public function __construct(object $result, string $message = 'unauthorized')
    {
        parent::__construct($result, $message);
    }
}
