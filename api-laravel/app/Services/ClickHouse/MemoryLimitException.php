<?php

declare(strict_types=1);

namespace App\Services\ClickHouse;

use RuntimeException;

/**
 * Port of Events::Stores::Clickhouse::MemoryLimitError
 * (app/services/events/stores/clickhouse/memory_limit_error.rb) — raised
 * when a query hits ClickHouse's MEMORY_LIMIT_EXCEEDED so callers (usage
 * computation) can fail the charge without retrying.
 */
class MemoryLimitException extends RuntimeException {}
