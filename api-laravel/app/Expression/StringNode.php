<?php

declare(strict_types=1);

namespace App\Expression;

/** Port of `Expression::String(String)` — a single-quoted string literal. */
final class StringNode implements Expression
{
    public function __construct(
        public readonly string $value,
    ) {}
}
