<?php

declare(strict_types=1);

namespace App\Expression;

/** Port of the gem's Operation enum — the four arithmetic operators. */
enum BinaryOperator
{
    case Add;
    case Subtract;
    case Multiply;
    case Divide;

    /** Binding precedence; add/sub bind looser than mul/div. */
    public function precedence(): int
    {
        return match ($this) {
            self::Add, self::Subtract => 1,
            self::Multiply, self::Divide => 2,
        };
    }
}
