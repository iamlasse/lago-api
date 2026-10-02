<?php

declare(strict_types=1);

namespace App\Services\Failures;

class ForbiddenFailure extends FailedResult
{
    /**
     * The failure's error code. \Exception carries its own untyped int
     * $code, so this shadow cannot be typed or readonly.
     */
    public $code;

    public function __construct(object $result, string $code = 'feature_unavailable')
    {
        $this->code = $code;

        parent::__construct($result, $code);
    }
}
