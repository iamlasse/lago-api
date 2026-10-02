<?php

declare(strict_types=1);

namespace App\Models\Casts;

use InvalidArgumentException;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

/**
 * For Postgres `numeric(p,s)` columns (e.g. fees.precise_amount_cents
 * numeric(40,15)). PDO returns these as strings; this cast keeps them as
 * exact bcmath strings at the column's scale so no float ever touches
 * billing math. Arithmetic on these values must use bcmath.
 */
class BcNumeric implements CastsAttributes
{
    public function __construct(
        private readonly int $scale = 15,
    ) {}

    public function get($model, string $key, $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return bcadd((string) $value, '0', $this->scale);
    }

    public function set($model, string $key, $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_float($value) || is_int($value)) {
            $value = (string) $value;
        }

        // Reject anything that isn't a plain numeric string — no floats, no
        // scientific notation leaking into money columns.
        if (! preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            throw new InvalidArgumentException(sprintf(
                'Non-exact numeric value assigned to %s::%s: %s',
                get_class($model),
                $key,
                var_export($value, true),
            ));
        }

        return bcadd($value, '0', $this->scale);
    }
}
