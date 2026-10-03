<?php

declare(strict_types=1);

namespace App\Expression;

/** Port of `Expression::BinOp { lhs, op, rhs }` — left-associative. */
final class BinaryOperationNode implements Expression
{
    public function __construct(
        public readonly Expression $lhs,
        public readonly BinaryOperator $operator,
        public readonly Expression $rhs,
    ) {}
}
