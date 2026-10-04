<?php

declare(strict_types=1);

namespace App\Services\Invoices\Payments;

use RuntimeException;

/**
 * Port of Rails' RetriableError (app/support/retriable_error.rb) — raised
 * when a payment attempt should be retried by the job layer.
 */
class RetriableError extends RuntimeException {}
