<?php

declare(strict_types=1);

namespace App\Expression;

/**
 * Port of `Expression::UnaryMinus(...)` — the grammar allows a single
 * prefix minus on an atom ("--1" does not parse, "1 - -2" does).
 */
final class UnaryMinusNode implements Expression
{
    public function __construct(
        public readonly Expression $inner,
    ) {}
}
