<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * charges.charge_model — integer column, Rails enum order is the stored
 * value, 0-based (app/models/charge.rb CHARGE_MODELS). Never renumber.
 *
 * NOTE: fixed_charges.charge_model is a *native Postgres enum* storing the
 * strings 'standard' | 'graduated' | 'volume' — do not use this integer enum
 * for FixedCharge rows.
 */
enum ChargeModel: int
{
    case Standard = 0;
    case Graduated = 1;
    case Package = 2;
    case Percentage = 3;
    case Volume = 4;
    case GraduatedPercentage = 5;
    case Custom = 6;
    case Dynamic = 7;

    /** @return list<string> Rails' Charge::CHARGE_MODELS names, in order. */
    public static function options(): array
    {
        return [
            'standard', 'graduated', 'package', 'percentage',
            'volume', 'graduated_percentage', 'custom', 'dynamic',
        ];
    }

    /**
     * Rails assigns the enum NAME ("standard" | … | "dynamic") and the column
     * stores the integer position; returns the position, or null when the
     * name is not one of the options.
     */
    public static function fromOption(mixed $value): ?int
    {
        if (is_int($value)) {
            return self::tryFrom($value) !== null ? $value : null;
        }

        return match (is_string($value) ? mb_strtolower($value) : null) {
            'standard' => self::Standard->value,
            'graduated' => self::Graduated->value,
            'package' => self::Package->value,
            'percentage' => self::Percentage->value,
            'volume' => self::Volume->value,
            'graduated_percentage' => self::GraduatedPercentage->value,
            'custom' => self::Custom->value,
            'dynamic' => self::Dynamic->value,
            default => null,
        };
    }

    /** The Rails enum name (the string the REST API emits for the value). */
    public function label(): string
    {
        return \Illuminate\Support\Str::snake($this->name);
    }
}
