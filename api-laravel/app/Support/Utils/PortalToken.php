<?php

declare(strict_types=1);

namespace App\Support\Utils;

use RuntimeException;

/**
 * Port of the customer-portal token mechanism. Rails signs the portal token
 * with `ActiveSupport::MessageVerifier.new(ENV["SECRET_KEY_BASE"])`:
 *
 * - `CustomerPortal::GenerateUrlService` mints the message with
 *   `generate(customer.id, expires_in: 12.hours)`;
 * - the `CustomerPortalUser` controller concern verifies the
 *   `customer-portal-token` request header with `verify(token)` and looks the
 *   customer up by the recovered id (an invalid signature answers nil).
 *
 * The wire shape mirrors Rails' verifier format — `data--digest`, where the
 * payload is a serialized message and the digest an HMAC over it — with JSON
 * standing in for Marshal (PHP cannot produce Ruby's Marshal stream, so the
 * tokens are NOT byte-compatible with Rails-minted ones; a token is only
 * ever verified by the same engine that minted it). The expiry Rails stores
 * inside the message metadata travels in the payload (`exp`), and like Rails'
 * verifier an expired or tampered token simply fails to verify (nil).
 */
class PortalToken
{
    /** Rails: `expires_in: 12.hours` in CustomerPortal::GenerateUrlService. */
    public const EXPIRES_IN_HOURS = 12;

    /** Rails: `ActiveSupport::MessageVerifier` default digest (SHA256). */
    private const DIGEST = 'sha256';

    /** Rails: the message and its digest are joined with "--". */
    private const SEPARATOR = '--';

    /** Rails: `ActiveSupport::MessageVerifier.new(ENV["SECRET_KEY_BASE"])`. */
    public static function generate(string $customerId): string
    {
        $data = self::encode(json_encode([
            'customer_id' => $customerId,
            'exp' => now()->getTimestamp() + (self::EXPIRES_IN_HOURS * 3600),
        ], JSON_THROW_ON_ERROR));

        return $data.self::SEPARATOR.self::encode(self::signature($data));
    }

    /**
     * Rails: `public_authenticator.verify(token)` — the customer id for a
     * genuine, unexpired token; nil otherwise (Rails rescues
     * InvalidSignature, which covers both tampering and expiry).
     */
    public static function verify(?string $token): ?string
    {
        if ($token === null || $token === '') {
            return null;
        }

        $parts = explode(self::SEPARATOR, $token);

        if (count($parts) !== 2) {
            return null;
        }

        [$data, $digest] = $parts;

        $expected = self::decode($digest);

        if ($expected === null || ! hash_equals($expected, self::signature($data))) {
            return null;
        }

        $payload = self::decodeJson(self::decode($data));

        if ($payload === null || ! is_string($payload['customer_id'] ?? null)) {
            return null;
        }

        if (! is_numeric($payload['exp'] ?? null) || (int) $payload['exp'] < now()->getTimestamp()) {
            return null;
        }

        return $payload['customer_id'];
    }

    /** HMAC over the serialized data, keyed by the Rails secret. */
    private static function signature(string $data): string
    {
        return hash_hmac(self::DIGEST, $data, self::secret());
    }

    private static function secret(): string
    {
        return (string) env('SECRET_KEY_BASE');
    }

    /** Rails: `Base64.strict_encode64` of the serialized message. */
    private static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function decode(string $encoded): ?string
    {
        try {
            $raw = base64_decode(strtr($encoded, '-_', '+/'), true);
        } catch (RuntimeException) {
            return null;
        }

        return $raw === false ? null : $raw;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decodeJson(?string $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }
}
