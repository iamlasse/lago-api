<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use App\Support\Utils\AuthToken;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;

/**
 * Port of spec/services/utils/auth_token_spec.rb (Rails) adapted to the
 * firebase/php-jwt implementation.
 *
 * Cross-language note: byte-compatibility with Ruby's jwt gem (HS256) follows
 * from the shared JOSE construction — base64url(JSON(header)) .
 * base64url(JSON(payload)) . base64url(HMAC-SHA256(…, SECRET_KEY_BASE)) —
 * asserted below down to the raw HMAC bytes. The full cross-validation
 * (Rails-minted token authenticating in Laravel and vice versa) happens in
 * the auth_org contract scenario (tests/Contract).
 */
uses()->group('ledger:svc:Utils.AuthToken');

it('encodes a 3-segment base64url HS256 token with a sub and 3h exp', function (): void {
    $token = AuthToken::encode(userId: '0199d4f2-7d3a-7de1-b5ee-0242ac120002');

    expect($token)->toBeString();

    $segments = explode('.', (string) $token);

    expect($segments)->toHaveCount(3)
        ->and(preg_match('/^[A-Za-z0-9_-]+$/', $segments[0]))->toBe(1)
        ->and(preg_match('/^[A-Za-z0-9_-]+$/', $segments[1]))->toBe(1)
        ->and(str_contains((string) $token, '='))->toBeFalse()
        ->and(str_contains((string) $token, '+'))->toBeFalse()
        ->and(str_contains((string) $token, '/'))->toBeFalse();

    $header = json_decode(base64_decode(strtr($segments[0], '-_', '+/')), true);

    expect($header['alg'])->toBe('HS256')
        ->and($header['typ'])->toBe('JWT');

    $payload = json_decode(base64_decode(strtr($segments[1], '-_', '+/')), true);

    expect($payload['sub'])->toBe('0199d4f2-7d3a-7de1-b5ee-0242ac120002')
        ->and($payload['exp'])->toBeGreaterThanOrEqual(time() + AuthToken::THREE_HOURS - 2)
        ->and($payload['exp'])->toBeLessThanOrEqual(time() + AuthToken::THREE_HOURS + 2);
});

it('produces the exact HMAC-SHA256 signature the Ruby jwt gem would', function (): void {
    // Fixed secret + fixed payload → deterministic signature. Rails signs the
    // same bytes: base64url(header) . "." . base64url(payload) with
    // HMAC-SHA256 over ENV["SECRET_KEY_BASE"].
    $payload = ['sub' => 'user-1', 'exp' => 1_700_000_000, 'login_method' => 'email_password'];
    $token = JWT::encode($payload, env('SECRET_KEY_BASE'), 'HS256');

    [$headerSeg, $payloadSeg, $signatureSeg] = explode('.', $token);

    $expectedSignature = mb_rtrim(strtr(base64_encode(
        hash_hmac('sha256', $headerSeg.'.'.$payloadSeg, (string) env('SECRET_KEY_BASE'), true)
    ), '+/', '-_'), '=');

    expect($signatureSeg)->toBe($expectedSignature);
});

it('round-trips extra claims and keeps the payload key order like Rails', function (): void {
    $token = AuthToken::encode(userId: 'user-1', extra: ['login_method' => 'email_password']);

    $payload = AuthToken::decode($token);

    expect($payload['sub'])->toBe('user-1')
        ->and($payload['login_method'])->toBe('email_password');

    // Rails: {sub:, exp:}.merge(extra) — JSON key order is sub, exp, extra.
    $payloadSegment = explode('.', (string) $token)[1];
    $rawPayload = base64_decode(strtr($payloadSegment, '-_', '+/'));

    expect($rawPayload)->toBe('{"sub":"user-1","exp":'.AuthToken::decode($token)['exp'].',"login_method":"email_password"}');
});

it('encodes a user object as well as an explicit id', function (): void {
    $user = new App\Models\User;
    $user->id = 'user-2';

    $token = AuthToken::encode(user: $user);

    expect(AuthToken::decode($token)['sub'])->toBe('user-2');
});

it('returns null when encoding without a user id', function (): void {
    expect(AuthToken::encode())->toBeNull()
        ->and(AuthToken::encode(userId: ''))->toBeNull();
});

it('returns null when decoding a blank token', function (): void {
    expect(AuthToken::decode(null))->toBeNull()
        ->and(AuthToken::decode(''))->toBeNull();
});

it('rejects a token signed with a different secret', function (): void {
    $forged = JWT::encode(['sub' => 'user-1', 'exp' => time() + 100], 'wrong-secret-0123456789abcdef0123456789', 'HS256');

    expect(fn () => AuthToken::decode($forged))->toThrow(SignatureInvalidException::class);
});

it('rejects an expired token with an expired signature error', function (): void {
    $expired = JWT::encode(['sub' => 'user-1', 'exp' => time() - 100], env('SECRET_KEY_BASE'), 'HS256');

    expect(fn () => AuthToken::decode($expired))->toThrow(ExpiredException::class);
});

it('renews a token keeping extra claims with a fresh expiry', function (): void {
    // Travel close to expiry (< 1h remaining) so renew is meaningful.
    $token = JWT::encode(
        ['sub' => 'user-1', 'exp' => time() + 1800, 'login_method' => 'email_password'],
        env('SECRET_KEY_BASE'),
        'HS256',
    );

    $renewed = AuthToken::renew($token);

    expect($renewed)->toBeString()
        ->and($renewed)->not->toBe($token);

    $payload = AuthToken::decode($renewed);

    expect($payload['sub'])->toBe('user-1')
        ->and($payload['login_method'])->toBe('email_password')
        ->and($payload['exp'])->toBeGreaterThanOrEqual(time() + AuthToken::THREE_HOURS - 2);
});

it('renews from extra claims other than sub/exp/alg', function (): void {
    // Rails: extra = decoded.except(*non_extra_attributes) — everything except
    // sub/exp/alg survives the renewal.
    expect(AuthToken::nonExtraAttributes())->toBe(['sub', 'exp', 'alg']);

    $token = AuthToken::encode(userId: 'user-3', extra: ['login_method' => 'google_oauth']);
    $payload = AuthToken::decode(AuthToken::renew($token));

    expect($payload['login_method'])->toBe('google_oauth')
        ->and($payload['sub'])->toBe('user-3');
});

it('returns null renewing a blank token', function (): void {
    expect(AuthToken::renew(null))->toBeNull()
        ->and(AuthToken::renew(''))->toBeNull();
});
