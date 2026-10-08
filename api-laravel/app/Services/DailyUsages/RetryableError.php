<?php

declare(strict_types=1);

namespace App\Services\DailyUsages;

use RuntimeException;

/**
 * Port of Rails' DailyUsages::RetryableError
 * (app/services/daily_usages/retryable_error.rb) — the retriable marker
 * DailyUsages\FillHistoryJob's retry_on waits on.
 */
class RetryableError extends RuntimeException {}
