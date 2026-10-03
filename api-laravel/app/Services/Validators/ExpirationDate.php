<?php

declare(strict_types=1);

namespace App\Services\Validators;

use App\Support\Utils\Datetime;

/**
 * Port of Rails' Validators::ExpirationDateValidator
 * (app/services/validators/expiration_date_validator.rb) — a blank
 * expiration is allowed; anything present must parse as a date and lie in
 * the future.
 */
final class ExpirationDate
{
    /** Rails: `valid?(expiration_at)`. */
    public static function valid(mixed $expirationAt): bool
    {
        if ($expirationAt === null || $expirationAt === '') {
            return true;
        }

        return Datetime::validFormat($expirationAt, 'any')
            && Datetime::futureDate($expirationAt);
    }
}
