<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator;

/**
 * Port of Rails' Integrations::Aggregator::BaseService error classes
 * (app/services/integrations/aggregator/base_service.rb) — the retryable
 * provider plumbing errors the tax jobs re-dispatch on.
 */
class BadGatewayError extends AggregatorError {}

class RequestLimitError extends AggregatorError {}

class OutOfMemoryError extends AggregatorError {}

class TaskInProgressError extends AggregatorError {}

class TaskExpiredError extends AggregatorError {}

class OrchestratorFailureError extends AggregatorError {}

class ServerContentionError extends AggregatorError {}

class TimeoutError extends AggregatorError {}
