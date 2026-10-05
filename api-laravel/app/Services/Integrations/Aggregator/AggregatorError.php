<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator;

use RuntimeException;

/**
 * Base class of the Rails Integrations::Aggregator::BaseService error
 * subclasses (BadGatewayError, RequestLimitError, …).
 */
class AggregatorError extends RuntimeException {}
