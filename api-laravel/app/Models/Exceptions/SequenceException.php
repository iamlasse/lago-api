<?php

declare(strict_types=1);

namespace App\Models\Exceptions;

use RuntimeException;

/**
 * Port of Rails' SequenceError raised by the Sequenced concern when the
 * pg advisory lock cannot be acquired (lock_timeout exceeded) — retryable.
 */
class SequenceException extends RuntimeException {}
