<?php

declare(strict_types=1);

namespace App\Expression;

/** A lexical token with its source position (0-based character offset). */
final class Token
{
    public function __construct(
        public readonly TokenType $type,
        /** Literal text for lexicals, identifier name, or string contents. */
        public readonly string $value,
        public readonly int $position,
    ) {}
}
