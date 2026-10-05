<?php

declare(strict_types=1);

namespace App\Services\QuoteVersions;

use RuntimeException;
use App\Services\BaseResult;

/**
 * Port of Rails' QuoteVersions::CloneService::CloneError — carries the
 * source failure so the clone answers service_failure(code: "clone_failed").
 */
class CloneError extends RuntimeException
{
    public function __construct(
        public readonly ?BaseResult $sourceResult = null,
    ) {
        parent::__construct('QuoteVersion clone failed: '.($sourceResult?->getError()?->getMessage() ?? ''));
    }

    public static function fromResult(BaseResult $result): self
    {
        return new self($result);
    }
}
