<?php

declare(strict_types=1);

namespace App\Expression;

/** Port of `Expression::Decimal(BigDecimal)` — a numeric literal. */
final class NumberNode implements Expression
{
    public function __construct(
        /** Canonical decimal string, scale preserved from the literal. */
        public readonly string $value,
    ) {}
}
