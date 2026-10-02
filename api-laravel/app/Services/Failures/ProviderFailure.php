<?php

declare(strict_types=1);

namespace App\Services\Failures;

use Throwable;

class ProviderFailure extends FailedResult
{
    public function __construct(object $result, public readonly string $provider, ?Throwable $error = null)
    {
        parent::__construct($result, '', $error);
    }
}
