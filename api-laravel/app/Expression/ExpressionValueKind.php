<?php

declare(strict_types=1);

namespace App\Expression;

/**
 * Port of the gem's ExpressionValue enum: an expression evaluates either to
 * a decimal number or to a string (concat, string literals, event code).
 */
enum ExpressionValueKind
{
    case Number;
    case String;
}
