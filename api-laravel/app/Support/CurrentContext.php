<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Port of Rails' CurrentContext (lib/current_context.rb) — request-scoped
 * attributes used for audit trails (versions.whodunnit), api_logs and
 * organization scoping.
 */
final class CurrentContext
{
    public static ?object $membership = null;

    public static ?string $source = null;

    public static ?string $email = null;

    public static ?string $apiKeyId = null;

    public static ?array $deviceInfo = null;

    /** The organization resolved from the request (API key or membership switch). */
    public static ?object $organization = null;

    public static function hasOrganization(): bool
    {
        return self::$organization !== null;
    }

    /** whodunnit value for the versions audit table (mirrors CurrentContext rails identity). */
    public static function whodunnit(): ?string
    {
        if (self::$email !== null) {
            return self::$email;
        }

        return self::$apiKeyId;
    }

    public static function reset(): void
    {
        self::$membership = null;
        self::$source = null;
        self::$email = null;
        self::$apiKeyId = null;
        self::$deviceInfo = null;
        self::$organization = null;
    }
}
