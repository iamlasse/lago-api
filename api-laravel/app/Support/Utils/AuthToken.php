<?php

declare(strict_types=1);

namespace App\Support\Utils;

use Firebase\JWT\JWT;
use UnexpectedValueException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;

/**
 * Port of Rails' Utils::AuthToken (app/services/utils/auth_token.rb).
 *
 * HS256 JWT signed with env SECRET_KEY_BASE, 3h expiry, payload
 * `{sub: user_id, exp, **extra}`. Tokens must be byte-compatible with Rails'
 * `jwt` gem output: firebase/php-jwt and the jwt gem both emit
 * base64url(JSON(header)).base64url(JSON(payload)).HMAC-SHA256, with the JSON
 * keys in insertion order — so the payload array is built in the same order as
 * Rails' `{sub:, exp:}.merge(extra)`. Cross-validation against Rails-minted
 * tokens happens in the auth_org contract scenario (tests/Contract).
 */
class AuthToken
{
    public const THREE_HOURS = 10800;

    public const ALGORITHM = 'HS256';

    public const LAGO_TOKEN_HEADER = 'x-lago-token';

    /**
     * Rails: encode(user: nil, user_id: nil, **extra) — returns nil when the
     * user id is blank.
     */
    public static function encode(?object $user = null, ?string $userId = null, array $extra = []): ?string
    {
        $resolvedId = $userId ?? $user?->id;

        if (blank($resolvedId)) {
            return null;
        }

        return JWT::encode(self::payload($user, $userId, $extra), self::secret(), self::ALGORITHM);
    }

    /**
     * Rails: decode returns the payload hash, or nil when the token is blank.
     *
     * @return array<string, mixed>|null
     *
     * @throws ExpiredException when the exp claim is in the past (mirrors JWT::ExpiredSignature)
     * @throws SignatureInvalidException|UnexpectedValueException for invalid tokens
     */
    public static function decode(?string $token): ?array
    {
        if ($token === null || $token === '') {
            return null;
        }

        // JWT::decode with a keyed secret asserts the algorithm and verifies
        // exp; mirror Rails' `reduce({}, :merge)` — return the payload array.
        return (array) JWT::decode($token, self::secret());
    }

    /**
     * Rails: renew — re-encode keeping every claim except sub/exp/alg, with a
     * fresh exp.
     */
    public static function renew(?string $token): ?string
    {
        if ($token === null || $token === '') {
            return null;
        }

        $decoded = self::decode($token);
        if ($decoded === null) {
            return null;
        }

        $userId = $decoded['sub'] ?? null;
        $extra = array_diff_key($decoded, array_flip(self::nonExtraAttributes()));

        return self::encode(userId: is_scalar($userId) ? (string) $userId : null, extra: $extra);
    }

    /** Rails: non_extra_attributes = ["sub", "exp", "alg"]. */
    public static function nonExtraAttributes(): array
    {
        return ['sub', 'exp', 'alg'];
    }

    /**
     * Rails: payload — `{sub:, exp: Time.current.to_i + THREE_HOURS}.merge(extra)`.
     * Key order is preserved so the JSON payload matches Rails byte-for-byte.
     */
    private static function payload(?object $user, ?string $userId, array $extra): array
    {
        return array_merge([
            'sub' => $userId ?? $user->id,
            'exp' => now()->getTimestamp() + self::THREE_HOURS,
        ], $extra);
    }

    private static function secret(): string
    {
        return (string) env('SECRET_KEY_BASE');
    }
}
