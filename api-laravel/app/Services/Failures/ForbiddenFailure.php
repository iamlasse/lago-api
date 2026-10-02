<?php

declare(strict_types=1);

namespace App\Services\Failures;

class ForbiddenFailure extends FailedResult
{
    public function __construct(object $result, public readonly string $code = 'feature_unavailable')
    {
        parent::__construct($result, $code);
    }
}
