<?php

declare(strict_types=1);

namespace App\Expression;

/**
 * Port of the rust bigdecimal RoundingMode variants the gem uses:
 * HalfUp rounds halves away from zero, Ceiling toward +inf, Floor toward
 * -inf.
 */
enum RoundingMode
{
    case HalfUp;
    case Ceiling;
    case Floor;
}
