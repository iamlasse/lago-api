<?php

declare(strict_types=1);

namespace App\Services\Failures;

use Throwable;

class ServiceFailure extends FailedResult
{
    /**
     * The failure's error code. \Exception carries its own untyped int
     * $code, so this shadow cannot be typed or readonly.
     */
    public $code;

    public function __construct(
        object $result,
        string $code,
        public readonly string $errorMessage,
        ?Throwable $originalError = null,
    ) {
        $this->code = $code;

        parent::__construct($result, $code.': '.$errorMessage, $originalError);
    }
}
