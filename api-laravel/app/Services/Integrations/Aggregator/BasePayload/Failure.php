<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\BasePayload;

use App\Services\Failures\FailedResult;

/**
 * Port of Rails' Integrations::Aggregator::BasePayload::Failure — the
 * payload-level failure (e.g. "invalid_mapping") raised mid-body and
 * converted into a non-retryable result by the collector services.
 */
class Failure extends FailedResult
{
    private string $failureCode;

    public function __construct(string $code)
    {
        $this->failureCode = $code;

        parent::__construct((object) [], $code);
    }

    /** Rails: `attr_reader :code`. */
    public function code(): string
    {
        return $this->failureCode;
    }
}
