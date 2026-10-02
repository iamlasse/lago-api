<?php

declare(strict_types=1);

namespace App\Services\Validators;

/**
 * Port of Rails' EmailSanitizer (app/support/email_sanitizer.rb) and
 * Regex::EMAIL (app/support/regex.rb) — applied through `normalizes :email`
 * on Organization / BillingEntity / Customer.
 */
final class EmailSanitizer
{
    /**
     * Regex::DASH_LOOKALIKES_CHARS.
     */
    private const DASH_LOOKALIKES = '/[\x{2012}\x{2013}\x{2014}\x{2015}\x{2043}\x{2212}]/u';

    /**
     * Regex::INVISIBLE_CHARS.
     */
    private const INVISIBLE_CHARS = '/[\x{200B}\x{200C}\x{200D}\x{00A0}\x{200E}\x{200F}\x{FEFF}]/u';

    /**
     * Regex::EMAIL (RFC5322-derived, unicode local part allowed).
     */
    private const EMAIL =
        '/^(?:[\p{L}\p{M}\p{N}!#$%&\'*+\/=?^_`{|}~-]+(?:\.[\p{L}\p{M}\p{N}!#$%&\'*+\/=?^_`{|}~-]+)*|"(?:[\x01-\x08\x0b\x0c\x0e-\x1f\x21\x23-\x5b\x5d-\x7f]|\\\\[\x01-\x09\x0b\x0c\x0e-\x7f])*")@(?:(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]*[a-z0-9])?|\[(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?|[a-z0-9-]*[a-z0-9]:(?:[\x01-\x08\x0b\x0c\x0e-\x1f\x21-\x5a\x53-\x7f]|\\\\[\x01-\x09\x0b\x0c\x0e-\x7f])+)\])$/ixu';

    public static function sanitize(?string $email): ?string
    {
        if ($email === null || $email === '') {
            return $email;
        }

        $email = preg_replace(self::DASH_LOOKALIKES, '-', $email);
        $email = preg_replace(self::INVISIBLE_CHARS, '', $email);

        return mb_trim((string) $email);
    }

    public static function valid(?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        // Rails splits on commas keeping trailing empties (`split(",", -1)`)
        // — an empty segment does not match, so trailing commas are invalid.
        $emails = explode(',', $value);

        foreach ($emails as $email) {
            if (preg_match(self::EMAIL, mb_trim($email)) !== 1) {
                return false;
            }
        }

        return true;
    }
}
