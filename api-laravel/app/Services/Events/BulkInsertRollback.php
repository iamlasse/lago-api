<?php

declare(strict_types=1);

namespace App\Services\Events;

use RuntimeException;

/**
 * Internal signal standing in for Rails' `raise ActiveRecord::Rollback`
 * inside Events::CreateBatchService's insert transaction — caught locally,
 * never surfaced to callers.
 */
final class BulkInsertRollback extends RuntimeException {}
