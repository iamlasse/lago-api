<?php

declare(strict_types=1);

namespace App\Expression;

use Exception;

/**
 * Port of the gem's ParseError — the grammar rejected the input. Rails maps
 * this to the invalid_expression validation error.
 */
final class ExpressionParseException extends Exception {}
