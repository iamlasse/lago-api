<?php

namespace App\Services\Failures;

class UnknownTaxFailure extends FailedResult
{
    public function __construct(
        object $result,
        public readonly string $code,
        public readonly string $errorMessage,
    ) {
        parent::__construct($result, $code.': '.$errorMessage);
    }
}
