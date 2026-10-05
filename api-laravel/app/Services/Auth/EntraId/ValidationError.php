<?php

declare(strict_types=1);

namespace App\Services\Auth\EntraId;

use RuntimeException;

/**
 * Port of the `ValidationError < StandardError` nested in Rails'
 * Auth::EntraId::BaseService — the guard methods raise it and the services
 * map its message to a single_validation_failure.
 */
class ValidationError extends RuntimeException {}
