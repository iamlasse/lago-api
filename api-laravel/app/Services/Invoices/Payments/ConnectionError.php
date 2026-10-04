<?php

declare(strict_types=1);

namespace App\Services\Invoices\Payments;

use Throwable;
use RuntimeException;

/**
 * Port of Rails' Invoices::Payments::ConnectionError — wraps a provider
 * connection error so the payment job can retry with backoff.
 */
class ConnectionError extends RuntimeException
{
    public function __construct(
        public readonly Throwable $initialError,
    ) {
        parent::__construct($initialError->getMessage(), 0, $initialError);
    }
}
