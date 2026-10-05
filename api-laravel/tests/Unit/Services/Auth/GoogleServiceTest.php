<?php

declare(strict_types=1);

uses()->group('ledger:svc:Auth.GoogleService');

use App\Models\Role;
use App\Models\User;
use Firebase\JWT\JWT;
use App\Models\Invite;
use App\Models\Membership;
use App\Models\Organization;
use App\Support\Utils\AuthToken;
use App\Services\Auth\GoogleService;
use App\Services\Failures\ServiceFailure;
use App\Services\Failures\NotFoundFailure;
use App\Services\Failures\ValidationFailure;

/**
 * Port of spec/services/auth/google_service_spec.rb (Rails).
 *
 * The Rails spec stubs the google-auth gems; here the token exchange and the
 * JWKS endpoint are stubbed with Http::fake() and the id_token is signed with
 * a throwaway RSA key, so the RS256 verification path is exercised for real.
 */

/**
 * @return array{0: string, 1: array<string, mixed>} PEM private key + JWKS
 */
function googleKeyPair(): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $privatePem);
    $details = openssl_pkey_get_details($key);

    $b64 = fn (string $bytes): string => mb_rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

    $jwks = [
        'keys' => [[
            'kty' => 'RSA',
            'alg' => 'RS256',
            'use' => 'sig',
            'kid' => 'test-key',
            'n' => $b64($details['rsa']['n']),
            'e' => $b64($details['rsa']['e']),
        ]],
    ];

    return [$privatePem, $jwks];
}

function googleIdToken(string $privatePem, string $email, string $aud = 'client_id'): string
{
    return JWT::encode([
        'iss' => 'https://accounts.google.com',
        'aud' => $aud,
        'sub' => '1234567890',
        'email' => $email,
        'email_verified' => true,
        'exp' => time() + 3600,
        'iat' => time(),
    ], $privatePem, 'RS256', 'test-key');
}

function googleFakeHttp(?string $idToken = null, ?array $jwks = null): void
{
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['id_token' => $idToken ?? '']),
        'https://www.googleapis.com/oauth2/v3/certs' => Http::response($jwks ?? ['keys' => []]),
    ]);
}

function googleEnableAuth(): void
{
    config()->set('lago.google_auth_client_id', 'client_id');
    config()->set('lago.google_auth_client_secret', 'client_secret');
}

function googleCreateAdminRole(): Role
{
    // Rails: create(:role, :admin) — the single global admin role.
    return Role::create([
        'code' => 'admin',
        'name' => 'Admin',
        'admin' => true,
        'permissions' => [],
        'organization_id' => null,
    ]);
}

beforeEach(function (): void {
    App\Support\CurrentContext::reset();
});

it('returns the authorize url', function (): void {
    googleEnableAuth();

    $result = GoogleService::authorizeUrl();

    expect($result->success())->toBeTrue()
        ->and($result->url)->toContain('https://accounts.google.com/o/oauth2/auth')
        ->and($result->url)->toContain('client_id=client_id')
        ->and($result->url)->toContain('redirect_uri='.urlencode('https://app.getlago.com/auth/google/callback'))
        ->and($result->url)->toContain('scope='.urlencode('profile email openid'));
});

it('returns a service failure when google auth is not set up', function (): void {
    config()->set('lago.google_auth_client_id', null);
    config()->set('lago.google_auth_client_secret', null);

    $result = GoogleService::authorizeUrl();

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(ServiceFailure::class)
        ->and($result->getError()->code)->toBe('google_auth_missing_setup');
});

it('logs the user in', function (): void {
    googleEnableAuth();
    [$privatePem, $jwks] = googleKeyPair();
    googleFakeHttp(googleIdToken($privatePem, 'foo@bar.com'), $jwks);

    $user = User::factory()->create(['email' => 'foo@bar.com']);
    Organization::factory()->has(Membership::factory()->for($user), 'memberships')->create();

    $result = GoogleService::login('code');

    expect($result->success())->toBeTrue()
        ->and($result->user->id)->toBe($user->id)
        ->and($result->token)->not->toBeEmpty();

    $decoded = AuthToken::decode($result->token);

    expect($decoded['login_method'])->toBe('google_oauth')
        ->and($decoded['sub'])->toBe($user->id);
});

it('returns a validation failure when the user does not exist', function (): void {
    googleEnableAuth();
    [$privatePem, $jwks] = googleKeyPair();
    googleFakeHttp(googleIdToken($privatePem, 'foo@bar.com'), $jwks);

    $result = GoogleService::login('code');

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages)->toBe(['base' => ['user_does_not_exist']]);
});

it('returns a validation failure when the user has no active membership', function (): void {
    googleEnableAuth();
    [$privatePem, $jwks] = googleKeyPair();
    googleFakeHttp(googleIdToken($privatePem, 'foo@bar.com'), $jwks);

    User::factory()->create(['email' => 'foo@bar.com']);

    $result = GoogleService::login('code');

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['base' => ['user_does_not_exist']]);
});

it('returns a validation failure when the login method is not allowed', function (): void {
    googleEnableAuth();
    [$privatePem, $jwks] = googleKeyPair();
    googleFakeHttp(googleIdToken($privatePem, 'foo@bar.com'), $jwks);

    $user = User::factory()->create(['email' => 'foo@bar.com']);
    $organization = Organization::factory()->has(Membership::factory()->for($user), 'memberships')->create();
    $organization->authentication_methods = ['email_password'];
    $organization->save();

    $result = GoogleService::login('code');

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages)->toBe(['google_oauth' => ['login_method_not_authorized']]);
});

it('returns invalid_google_token when the id_token signature does not verify', function (): void {
    googleEnableAuth();
    [$otherPem] = googleKeyPair();
    [, $jwks] = googleKeyPair();
    googleFakeHttp(googleIdToken($otherPem, 'foo@bar.com'), $jwks);

    User::factory()->create(['email' => 'foo@bar.com']);
    Organization::factory()->has(Membership::factory(), 'memberships')->create();

    $result = GoogleService::login('code');

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages)->toBe(['base' => ['invalid_google_token']]);
});

it('returns invalid_google_code when the token exchange fails', function (): void {
    googleEnableAuth();
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    $result = GoogleService::login('code');

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['base' => ['invalid_google_code']]);
});

it('registers a new user', function (): void {
    googleEnableAuth();
    googleCreateAdminRole();
    [$privatePem, $jwks] = googleKeyPair();
    googleFakeHttp(googleIdToken($privatePem, 'foo@bar.com'), $jwks);

    $result = GoogleService::registerUser('code', 'Foobar');

    expect($result->success())->toBeTrue()
        ->and($result->user->email)->toBe('foo@bar.com')
        ->and($result->organization->name)->toBe('Foobar')
        ->and($result->membership->user_id)->toBe($result->user->id)
        ->and($result->token)->not->toBeEmpty();

    $decoded = AuthToken::decode($result->token);

    expect($decoded['login_method'])->toBe('google_oauth');
});

it('refuses to register an already existing user', function (): void {
    googleEnableAuth();
    [$privatePem, $jwks] = googleKeyPair();
    googleFakeHttp(googleIdToken($privatePem, 'foo@bar.com'), $jwks);

    User::factory()->create(['email' => 'foo@bar.com']);

    $result = GoogleService::registerUser('code', 'FooBar');

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['email' => ['user_already_exists']]);
});

it('accepts an invite', function (): void {
    googleEnableAuth();
    googleCreateAdminRole();
    [$privatePem, $jwks] = googleKeyPair();
    googleFakeHttp(googleIdToken($privatePem, 'foo@bar.com'), $jwks);

    $invite = Invite::factory()->create(['email' => 'foo@bar.com']);

    $result = GoogleService::acceptInvite('code', $invite->token);

    expect($result->success())->toBeTrue()
        ->and($result->user->email)->toBe('foo@bar.com')
        ->and($result->token)->not->toBeEmpty();

    $decoded = AuthToken::decode($result->token);

    expect($decoded['login_method'])->toBe('google_oauth')
        ->and($invite->fresh()->status->value)->toBe(1); // accepted
});

it('returns a not found failure when the invite does not exist', function (): void {
    googleEnableAuth();
    [$privatePem, $jwks] = googleKeyPair();
    googleFakeHttp(googleIdToken($privatePem, 'foo@bar.com'), $jwks);

    $result = GoogleService::acceptInvite('code', 'not_a_valid_token');

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class)
        ->and($result->getError()->resource)->toBe('invite');
});

it('returns a validation failure when the invite email does not match', function (): void {
    googleEnableAuth();
    [$privatePem, $jwks] = googleKeyPair();
    googleFakeHttp(googleIdToken($privatePem, 'foo@bar.com'), $jwks);

    $invite = Invite::factory()->create(['email' => 'other@bar.com']);

    $result = GoogleService::acceptInvite('code', $invite->token);

    // NOTE: the "mistmatch" typo is Rails' (kept verbatim).
    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['base' => ['invite_email_mistmatch']]);
});
