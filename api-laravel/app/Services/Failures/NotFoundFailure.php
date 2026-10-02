<?php

declare(strict_types=1);

namespace App\Services\Failures;

class NotFoundFailure extends FailedResult
{
    public function __construct(object $result, public readonly string $resource)
    {
        parent::__construct($result, self::errorCode($resource));
    }

    public static function errorCode(string $resource): string
    {
        return $resource.'_not_found';
    }
}
