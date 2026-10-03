<?php

declare(strict_types=1);

namespace App\Expression;

/**
 * Port of the gem's Function variants — round/ceil/floor carry their digit
 * argument in $digits (null when the one-argument form was used), the other
 * functions keep a flat argument list.
 */
final class FunctionNode implements Expression
{
    /** @param list<Expression> $arguments */
    public function __construct(
        public readonly FunctionName $name,
        public readonly array $arguments,
        public readonly ?Expression $digits = null,
    ) {}
}
