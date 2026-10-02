<?php

declare(strict_types=1);

namespace App\Services\Failures;

class ThirdPartyFailure extends FailedResult
{
    public function __construct(
        object $result,
        public readonly string $thirdParty,
        public readonly string $errorCode,
        public readonly string $errorMessage,
    ) {
        parent::__construct($result, $thirdParty.': '.$errorCode.' - '.$errorMessage);
    }
}
