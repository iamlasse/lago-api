<?php

declare(strict_types=1);

namespace App\Expression;

use Exception;

/**
 * Port of the gem's ExpressionError (raised as a RuntimeError through the
 * ruby extension): MissingVariable, ExpectedDecimal, EmptyArgumentList and
 * division by zero. Rails maps this to the invalid_event validation error.
 */
final class ExpressionEvaluationException extends Exception {}
