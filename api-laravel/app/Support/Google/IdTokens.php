<?php

declare(strict_types=1);

namespace App\Support\Google;

use Throwable;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use UnexpectedValueException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Firebase\JWT\SignatureInvalidException;

/**
 * HTTP-callable port of the google-auth gem's Google::Auth::IDTokens.verify_oidc
 * (used by Auth::GoogleService#oidc_verifier). No SDK is installed, so the
 * verification steps the gem performs are done by hand on Laravel's HTTP
 * client:
 *
 *   - the signature is RS256, verified against Google's public JWKS
 *     (https://www.googleapis.com/oauth2/v3/certs) — the gem's
 *     WebKeySource, with the same 6h key cache (HTTP_CACHE);
 *   - `aud` must equal the client id (the gem's check_aud);
 *   - `iss` must be accounts.google.com or https://accounts.google.com
 *     (the gem's check_iss for OIDC tokens);
 *   - `exp` is verified by firebase/php-jwt like the gem's check_exp.
 *
 * Fidelity note: the gem ALSO accepts a token whose signature comes from the
 * legacy x509 endpoint and verifies `nonce` when supplied — neither applies
 * to this flow (no nonce is issued).
 *
 * TODO(port): the gem pins the JWKS refresh with a mutex and re-fetches once
 * on an unknown kid; the port re-fetches per call when the kid is missing
 * from a cached key set.
 */
final class IdTokens
{
    public const string CERTS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    /** Rails gem: WebKeySource::CACHE_TTL = 6 hours. */
    public const int CACHE_TTL = 21600;

    public const array ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    /**
     * @return array<string, mixed> the verified token claims
     *
     * @throws SignatureInvalidException when the signature check fails (the
     *                                   caller maps this to "invalid_google_token", like Rails'
     *                                   Google::Auth::IDTokens::SignatureError rescue)
     * @throws UnexpectedValueException for malformed tokens, and for audience
     *                                  or issuer mismatches (Rails: the gem's InvalidAudienceError /
     *                                  InvalidIssuerError — NOT rescued, so they surface as 500s)
     */
    public static function verifyOidc(string $idToken, string $aud): array
    {
        $claims = (array) JWT::decode($idToken, self::keySet($idToken));

        if (($claims['aud'] ?? null) !== $aud) {
            throw new UnexpectedValueException('Invalid audience');
        }

        if (! in_array($claims['iss'] ?? null, self::ISSUERS, true)) {
            throw new UnexpectedValueException('Invalid issuer');
        }

        return $claims;
    }

    /** @return array<string, Firebase\JWT\Key> */
    private static function keySet(string $idToken): array
    {
        $kid = self::tokenKid($idToken);

        $cached = null;

        try {
            $cached = Cache::get('google_oauth_certs');
        } catch (Throwable) {
            // Cache unavailable — fall through to a live fetch.
        }

        if (is_array($cached) && $cached !== [] && ($kid === null || self::hasKey($cached, $kid))) {
            return JWK::parseKeySet($cached, 'RS256');
        }

        $jwks = Http::get(self::CERTS_URL)->throw()->json();

        try {
            Cache::put('google_oauth_certs', $jwks, self::CACHE_TTL);
        } catch (Throwable) {
            // Best-effort cache write.
        }

        return JWK::parseKeySet($jwks, 'RS256');
    }

    /** The token header's kid (what the gem uses to pick the verification key). */
    private static function tokenKid(string $idToken): ?string
    {
        $parts = explode('.', $idToken);

        if (count($parts) < 2) {
            return null;
        }

        $header = json_decode(JWT::urlsafeB64Decode($parts[0]), true);

        return is_array($header) && is_string($header['kid'] ?? null) ? $header['kid'] : null;
    }

    /** @param array<string, mixed> $jwks */
    private static function hasKey(array $jwks, string $kid): bool
    {
        return array_any((array) ($jwks['keys'] ?? []), fn($key) => ($key['kid'] ?? null) === $kid);
    }
}
