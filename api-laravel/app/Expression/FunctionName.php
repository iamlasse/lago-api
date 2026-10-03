<?php

declare(strict_types=1);

namespace App\Expression;

/**
 * Port of the gem's Function enum. The grammar accepts exactly three
 * spellings per function (lowercase, uppercase, capitalized) — anything
 * else ("cEil") is a parse error.
 */
enum FunctionName
{
    case Ceil;
    case Concat;
    case Round;
    case Floor;
    case Least;
    case Greatest;

    /** round/ceil/floor take 1..2 arguments; the rest are variadic. */
    public function hasOptionalSecondArgument(): bool
    {
        return match ($this) {
            self::Ceil, self::Round, self::Floor => true,
            self::Concat, self::Least, self::Greatest => false,
        };
    }

    public static function fromSpelling(string $spelling): ?self
    {
        return match ($spelling) {
            'ceil', 'CEIL', 'Ceil' => self::Ceil,
            'concat', 'CONCAT', 'Concat' => self::Concat,
            'round', 'ROUND', 'Round' => self::Round,
            'floor', 'FLOOR', 'Floor' => self::Floor,
            'least', 'LEAST', 'Least' => self::Least,
            'greatest', 'GREATEST', 'Greatest' => self::Greatest,
            default => null,
        };
    }
}
