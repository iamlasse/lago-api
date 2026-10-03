<?php

declare(strict_types=1);

namespace App\Models\Exceptions;

use RuntimeException;

/**
 * Port of Rails' ActiveRecord::StaleObjectError — raised when an
 * optimistic-lock (lock_version) update matches no rows because another
 * process saved the record first.
 */
final class StaleObjectError extends RuntimeException
{
    public function __construct(string $modelName)
    {
        parent::__construct("Attempted to update a stale object: {$modelName}");
    }
}
