<?php

declare(strict_types=1);

uses()->group('ledger:gql:mutation:googleLoginUser',
    'ledger:gql:mutation:googleRegisterUser',
    'ledger:gql:mutation:googleAcceptInvite',
    'ledger:gql:mutation:oktaAuthorize',
    'ledger:gql:mutation:oktaLogin',
    'ledger:gql:mutation:oktaAcceptInvite',
    'ledger:gql:mutation:entraIdAuthorize',
    'ledger:gql:mutation:entraIdLogin',
    'ledger:gql:mutation:entraIdAcceptInvite');

require_once __DIR__.'/GraphQLHelpers.php';

use App\Models\Role;
use App\Models\User;
use Firebase\JWT\JWT;
use App\Models\Invite;
use App\Support\CurrentContext;
use App\Support\Utils\AuthToken;
use App\Models\Integrations\OktaIntegration;
use App\Models\Integrations\EntraIdIntegration;

/**
 * GraphQL surface of the SSO slice — the nine frozen mutations
 * (googleLoginUser/googleRegisterUser/googleAcceptInvite,
 * oktaAuthorize/oktaLogin/oktaAcceptInvite,
 * entraIdAuthorize/entraIdLogin/entraIdAcceptInvite) exercised through
 * POST /graphql, matching the frozen-schema contract.
 */

/**
 * @return array{0: string, 1: array<string, mixed>} PEM private key + JWKS
 */
function ssoGoogleKeyPair(): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $privatePem);
    $details = openssl_pkey_get_details($key);

    $b64 = fn (string $bytes): string => mb_rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

    return [$privatePem, [
        'keys' => [[
            'kty' => 'RSA',
            'alg' => 'RS256',
            'use' => 'sig',
            'kid' => 'test-key',
            'n' => $b64($details['rsa']['n']),
            'e' => $b64($details['rsa']['e']),
        ]],
    ]];
}

function ssoGoogleFakeHttp(?string $idToken, array $jwks): void
{
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['id_token' => $idToken ?? '']),
        'https://www.googleapis.com/oauth2/v3/certs' => Http::response($jwks),
    ]);
}

beforeEach(function (): void {
    CurrentContext::reset();

    // Rails' spec_helper.rb: ENV["LAGO_WEBHOOK_ALLOW_PRIVATE_URLS"] ||= "true".
    $_ENV['LAGO_WEBHOOK_ALLOW_PRIVATE_URLS'] = 'true';
    $_SERVER['LAGO_WEBHOOK_ALLOW_PRIVATE_URLS'] = 'true';

    config()->set('lago.google_auth_client_id', 'client_id');
    config()->set('lago.google_auth_client_secret', 'client_secret');

    // Rails: create(:role, :admin).
    Role::create([
        'code' => 'admin',
        'name' => 'Admin',
        'admin' => true,
        'permissions' => [],
        'organization_id' => null,
    ]);
});

afterEach(function (): void {
    unset(\Illuminate\Support\Env::get('LAGO_WEBHOOK_ALLOW_PRIVATE_URLS'), $_SERVER['LAGO_WEBHOOK_ALLOW_PRIVATE_URLS']);
});

it('opens a session through googleLoginUser', function (): void {
    [$privatePem, $jwks] = ssoGoogleKeyPair();
    $idToken = JWT::encode([
        'iss' => 'https://accounts.google.com',
        'aud' => 'client_id',
        'sub' => '123',
        'email' => 'foo@bar.com',
        'exp' => time() + 3600,
    ], $privatePem, 'RS256', 'test-key');
    ssoGoogleFakeHttp($idToken, $jwks);

    $organization = gqlCreateOrganization();
    $user = User::factory()->create(['email' => 'foo@bar.com']);
    gqlCreateMembership($user, $organization);

    $response = gqlPost(/** @lang GraphQL */ <<<'GQL'
    mutation($input: GoogleLoginUserInput!) {
        googleLoginUser(input: $input) {
            token
            user { id email }
        }
    }
    GQL, ['input' => ['code' => 'code']]);

    $response->assertOk();

    $payload = $response->json('data.googleLoginUser');

    expect($payload['user']['id'])->toBe($user->id);

    $decoded = AuthToken::decode($payload['token']);

    expect($decoded['login_method'])->toBe('google_oauth');
});

it('registers through googleRegisterUser', function (): void {
    [$privatePem, $jwks] = ssoGoogleKeyPair();
    $idToken = JWT::encode([
        'iss' => 'https://accounts.google.com',
        'aud' => 'client_id',
        'sub' => '123',
        'email' => 'foo@bar.com',
        'exp' => time() + 3600,
    ], $privatePem, 'RS256', 'test-key');
    ssoGoogleFakeHttp($idToken, $jwks);

    $response = gqlPost(/** @lang GraphQL */ <<<'GQL'
    mutation($input: GoogleRegisterUserInput!) {
        googleRegisterUser(input: $input) {
            token
            user { id email }
            organization { id name }
            membership { id }
        }
    }
    GQL, ['input' => ['code' => 'code', 'organizationName' => 'FooBar']]);

    $response->assertOk();

    $payload = $response->json('data.googleRegisterUser');

    expect($payload['user']['email'])->toBe('foo@bar.com')
        ->and($payload['organization']['name'])->toBe('FooBar')
        ->and($payload['membership']['id'])->not->toBeEmpty();

    expect(AuthToken::decode($payload['token'])['login_method'])->toBe('google_oauth');
});

it('accepts an invite through googleAcceptInvite', function (): void {
    [$privatePem, $jwks] = ssoGoogleKeyPair();
    $idToken = JWT::encode([
        'iss' => 'https://accounts.google.com',
        'aud' => 'client_id',
        'sub' => '123',
        'email' => 'foo@bar.com',
        'exp' => time() + 3600,
    ], $privatePem, 'RS256', 'test-key');
    ssoGoogleFakeHttp($idToken, $jwks);

    $invite = Invite::factory()->create(['email' => 'foo@bar.com']);

    $response = gqlPost(/** @lang GraphQL */ <<<'GQL'
    mutation($input: GoogleAcceptInviteInput!) {
        googleAcceptInvite(input: $input) {
            token
            user { id email }
        }
    }
    GQL, ['input' => ['code' => 'code', 'inviteToken' => $invite->token]]);

    $response->assertOk();

    $payload = $response->json('data.googleAcceptInvite');

    expect($payload['user']['email'])->toBe('foo@bar.com')
        ->and($invite->fresh()->status->value)->toBe(1);

    expect(AuthToken::decode($payload['token'])['login_method'])->toBe('google_oauth');
});

it('surfaces the user_does_not_exist envelope for googleLoginUser', function (): void {
    [$privatePem, $jwks] = ssoGoogleKeyPair();
    $idToken = JWT::encode([
        'iss' => 'https://accounts.google.com',
        'aud' => 'client_id',
        'sub' => '123',
        'email' => 'ghost@bar.com',
        'exp' => time() + 3600,
    ], $privatePem, 'RS256', 'test-key');
    ssoGoogleFakeHttp($idToken, $jwks);

    $response = gqlPost(/** @lang GraphQL */ <<<'GQL'
    mutation($input: GoogleLoginUserInput!) {
        googleLoginUser(input: $input) {
            token
            user { id }
        }
    }
    GQL, ['input' => ['code' => 'code']]);

    $error = $response->json('errors.0');

    expect($error['extensions']['status'])->toBe(422)
        ->and($error['extensions']['code'])->toBe('unprocessable_entity')
        ->and($error['extensions']['details']['base'])->toContain('user_does_not_exist');
});

it('returns the authorize url through oktaAuthorize', function (): void {
    $integration = OktaIntegration::factory()->create();

    $response = gqlPost(/** @lang GraphQL */ <<<'GQL'
    mutation($input: OktaAuthorizeInput!) {
        oktaAuthorize(input: $input) { url }
    }
    GQL, ['input' => ['email' => 'foo@foo.test']]);

    $response->assertOk();

    $url = $response->json('data.oktaAuthorize.url');

    expect($url)->toContain('https://foobar.okta.com/oauth2/v1/authorize')
        ->and($url)->toContain('client_id='.$integration->clientId());
});

it('completes oktaLogin end to end', function (): void {
    $integration = OktaIntegration::factory()->create(['settings' => [
        'client_id' => 'client_id',
        'domain' => 'bar.com',
        'organization_name' => 'foo',
    ]]);
    $organization = $integration->organization;
    $organization->premium_integrations = ['okta'];
    $organization->authentication_methods = ['email_password', 'okta'];
    $organization->save();

    Http::fake([
        'https://foo.okta.com/oauth2/v1/token' => Http::response(['access_token' => 'access_token']),
        'https://foo.okta.com/oauth2/v1/userinfo' => Http::response(['email' => 'foo@bar.com']),
    ]);

    Cache::put($state = Illuminate\Support\Str::uuid()->toString(), 'foo@bar.com', 60);

    $response = gqlPost(/** @lang GraphQL */ <<<'GQL'
    mutation($input: OktaLoginInput!) {
        oktaLogin(input: $input) {
            token
            user { id email }
        }
    }
    GQL, ['input' => ['code' => 'code', 'state' => $state]]);

    $response->assertOk();

    $payload = $response->json('data.oktaLogin');

    expect($payload['user']['email'])->toBe('foo@bar.com')
        ->and(AuthToken::decode($payload['token'])['login_method'])->toBe('okta')
        ->and(Cache::get($state))->toBeNull(); // single-use state
});

it('surfaces the domain_not_configured envelope for oktaLogin', function (): void {
    Http::fake();

    Cache::put($state = Illuminate\Support\Str::uuid()->toString(), 'foo@bar.com', 60);

    $response = gqlPost(/** @lang GraphQL */ <<<'GQL'
    mutation($input: OktaLoginInput!) {
        oktaLogin(input: $input) {
            token
            user { id }
        }
    }
    GQL, ['input' => ['code' => 'code', 'state' => $state]]);

    $error = $response->json('errors.0');

    expect($error['extensions']['status'])->toBe(422)
        ->and($error['extensions']['details']['base'])->toContain('domain_not_configured');
});

it('accepts an invite through oktaAcceptInvite', function (): void {
    $organization = App\Models\Organization::factory()->create([
        'premium_integrations' => ['okta'],
        'authentication_methods' => ['email_password', 'okta'],
    ]);
    OktaIntegration::factory()->for($organization)->create(['settings' => [
        'client_id' => 'client_id',
        'domain' => 'bar.com',
        'organization_name' => 'foo',
    ]]);
    $invite = Invite::factory()->for($organization)->create(['email' => 'foo@bar.com']);

    Http::fake([
        'https://foo.okta.com/oauth2/v1/token' => Http::response(['access_token' => 'access_token']),
        'https://foo.okta.com/oauth2/v1/userinfo' => Http::response(['email' => 'foo@bar.com']),
    ]);

    Cache::put($state = Illuminate\Support\Str::uuid()->toString(), 'foo@bar.com', 60);

    $response = gqlPost(/** @lang GraphQL */ <<<'GQL'
    mutation($input: OktaAcceptInviteInput!) {
        oktaAcceptInvite(input: $input) {
            token
            user { id email }
        }
    }
    GQL, ['input' => ['code' => 'code', 'inviteToken' => $invite->token, 'state' => $state]]);

    $response->assertOk();

    $payload = $response->json('data.oktaAcceptInvite');

    expect($payload['user']['email'])->toBe('foo@bar.com')
        ->and($invite->fresh()->status->value)->toBe(1)
        ->and(AuthToken::decode($payload['token'])['login_method'])->toBe('okta');
});

it('returns the authorize url through entraIdAuthorize', function (): void {
    $integration = EntraIdIntegration::factory()->create();

    $response = gqlPost(/** @lang GraphQL */ <<<'GQL'
    mutation($input: EntraIdAuthorizeInput!) {
        entraIdAuthorize(input: $input) { url }
    }
    GQL, ['input' => ['email' => 'foo@foo.test']]);

    $response->assertOk();

    $url = $response->json('data.entraIdAuthorize.url');

    expect($url)->toContain('https://login.microsoftonline.com/'.$integration->tenantId().'/oauth2/v2.0/authorize');
});

it('completes entraIdLogin end to end', function (): void {
    $integration = EntraIdIntegration::factory()->create(['settings' => [
        'client_id' => 'client_id',
        'domain' => 'bar.com',
        'tenant_id' => 'tenant-1',
    ]]);
    $organization = $integration->organization;
    $organization->premium_integrations = ['entra_id'];
    $organization->authentication_methods = ['email_password', 'entra_id'];
    $organization->save();

    Http::fake([
        'https://login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response(['access_token' => 'access_token']),
        'https://graph.microsoft.com/oidc/userinfo' => Http::response(['preferred_username' => 'Foo@Bar.com']),
    ]);

    Cache::put($state = Illuminate\Support\Str::uuid()->toString(), 'foo@bar.com', 60);

    $response = gqlPost(/** @lang GraphQL */ <<<'GQL'
    mutation($input: EntraIdLoginInput!) {
        entraIdLogin(input: $input) {
            token
            user { id email }
        }
    }
    GQL, ['input' => ['code' => 'code', 'state' => $state]]);

    $response->assertOk();

    $payload = $response->json('data.entraIdLogin');

    expect($payload['user']['email'])->toBe('foo@bar.com')
        ->and(AuthToken::decode($payload['token'])['login_method'])->toBe('entra_id');
});

it('accepts an invite through entraIdAcceptInvite', function (): void {
    $organization = App\Models\Organization::factory()->create([
        'premium_integrations' => ['entra_id'],
        'authentication_methods' => ['email_password', 'entra_id'],
    ]);
    EntraIdIntegration::factory()->for($organization)->create(['settings' => [
        'client_id' => 'client_id',
        'domain' => 'bar.com',
        'tenant_id' => 'tenant-1',
    ]]);
    $invite = Invite::factory()->for($organization)->create(['email' => 'foo@bar.com']);

    Http::fake([
        'https://login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response(['access_token' => 'access_token']),
        'https://graph.microsoft.com/oidc/userinfo' => Http::response(['email' => 'foo@bar.com']),
    ]);

    Cache::put($state = Illuminate\Support\Str::uuid()->toString(), 'foo@bar.com', 60);

    $response = gqlPost(/** @lang GraphQL */ <<<'GQL'
    mutation($input: EntraIdAcceptInviteInput!) {
        entraIdAcceptInvite(input: $input) {
            token
            user { id email }
        }
    }
    GQL, ['input' => ['code' => 'code', 'inviteToken' => $invite->token, 'state' => $state]]);

    $response->assertOk();

    $payload = $response->json('data.entraIdAcceptInvite');

    expect($payload['user']['email'])->toBe('foo@bar.com')
        ->and($invite->fresh()->status->value)->toBe(1)
        ->and(AuthToken::decode($payload['token'])['login_method'])->toBe('entra_id');
});
