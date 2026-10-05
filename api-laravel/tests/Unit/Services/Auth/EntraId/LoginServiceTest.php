<?php

declare(strict_types=1);

uses()->group('ledger:svc:Auth.EntraId.LoginService');

use App\Models\User;
use App\Models\Membership;
use App\Support\CurrentContext;
use App\Support\Utils\AuthToken;
use App\Services\Auth\EntraId\LoginService;
use App\Models\Integrations\EntraIdIntegration;

/**
 * Port of spec/services/auth/entra_id/login_service_spec.rb (Rails) — the
 * Entra token endpoint and the Microsoft Graph userinfo endpoint are stubbed
 * with Http::fake() (Rails stubs LagoHttpClient::Client).
 */
beforeEach(function (): void {
    CurrentContext::reset();

    // Rails' spec_helper.rb: ENV["LAGO_WEBHOOK_ALLOW_PRIVATE_URLS"] ||= "true".
    $_ENV['LAGO_WEBHOOK_ALLOW_PRIVATE_URLS'] = 'true';
    $_SERVER['LAGO_WEBHOOK_ALLOW_PRIVATE_URLS'] = 'true';
});

afterEach(function (): void {
    unset($_ENV['LAGO_WEBHOOK_ALLOW_PRIVATE_URLS'], $_SERVER['LAGO_WEBHOOK_ALLOW_PRIVATE_URLS']);
});

function entraFakeHttp(array $tokenResponse = ['access_token' => 'access_token'], array $userinfoResponse = ['email' => 'foo@bar.com']): void
{
    Http::fake([
        'https://login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response($tokenResponse),
        'https://graph.microsoft.com/oidc/userinfo' => Http::response($userinfoResponse),
    ]);
}

function entraEnableLogin(EntraIdIntegration $integration): void
{
    $organization = $integration->organization;
    $organization->premium_integrations = ['entra_id'];
    $organization->authentication_methods = ['email_password', 'entra_id'];
    $organization->save();
}

it('creates user, membership and authenticates the user', function (): void {
    $integration = EntraIdIntegration::factory()->create(['settings' => [
        'client_id' => 'client_id',
        'domain' => 'bar.com',
        'tenant_id' => 'tenant-1',
    ]]);
    entraEnableLogin($integration);
    entraFakeHttp();

    Cache::put($state = Illuminate\Support\Str::uuid()->toString(), 'foo@bar.com', 60);

    $result = LoginService::call(code: 'code', state: $state);

    expect($result->success())->toBeTrue()
        ->and($result->user->email)->toBe('foo@bar.com')
        ->and($result->token)->not->toBeEmpty();

    $decoded = AuthToken::decode($result->token);

    expect($decoded['login_method'])->toBe('entra_id')
        ->and($result->user->memberships()->where('organization_id', $integration->organization_id)->exists())->toBeTrue();
});

it('hits the tenant token endpoint and the Microsoft Graph userinfo endpoint', function (): void {
    $integration = EntraIdIntegration::factory()->create(['settings' => [
        'client_id' => 'client_id',
        'domain' => 'bar.com',
        'tenant_id' => 'tenant-1',
    ]]);
    entraEnableLogin($integration);
    entraFakeHttp();

    Cache::put($state = Illuminate\Support\Str::uuid()->toString(), 'foo@bar.com', 60);

    LoginService::call(code: 'code', state: $state);

    Http::assertSentInOrder([
        fn ($request) => str_contains($request->url(), 'https://login.microsoftonline.com/tenant-1/oauth2/v2.0/token'),
        fn ($request) => $request->url() === 'https://graph.microsoft.com/oidc/userinfo',
    ]);
});

it('falls back to preferred_username and compares case-insensitively', function (): void {
    $integration = EntraIdIntegration::factory()->create(['settings' => [
        'client_id' => 'client_id',
        'domain' => 'bar.com',
        'tenant_id' => 'tenant-1',
    ]]);
    entraEnableLogin($integration);
    entraFakeHttp(userinfoResponse: ['preferred_username' => 'Foo@Bar.com']);

    Cache::put($state = Illuminate\Support\Str::uuid()->toString(), 'foo@bar.com', 60);

    $result = LoginService::call(code: 'code', state: $state);

    expect($result->success())->toBeTrue()
        ->and($result->user->email)->toBe('foo@bar.com');
});

it('returns an error when neither email nor preferred_username matches', function (): void {
    $integration = EntraIdIntegration::factory()->create(['settings' => [
        'client_id' => 'client_id',
        'domain' => 'bar.com',
        'tenant_id' => 'tenant-1',
    ]]);
    entraEnableLogin($integration);
    entraFakeHttp(userinfoResponse: ['email' => 'foo@test.com']);

    Cache::put($state = Illuminate\Support\Str::uuid()->toString(), 'foo@bar.com', 60);

    $result = LoginService::call(code: 'code', state: $state);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['base' => ['entra_id_userinfo_error']]);
});

it('returns an error when the code is not provided', function (): void {
    entraFakeHttp();

    Cache::put($state = Illuminate\Support\Str::uuid()->toString(), 'foo@bar.com', 60);

    $result = LoginService::call(code: '', state: $state);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['base' => ['code_not_found']]);
});

it('returns an error when the state is not provided', function (): void {
    entraFakeHttp();

    $result = LoginService::call(code: 'code', state: '');

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['base' => ['state_not_found']]);
});

it('returns an error when the login method is not allowed', function (): void {
    $integration = EntraIdIntegration::factory()->create(['settings' => [
        'client_id' => 'client_id',
        'domain' => 'bar.com',
        'tenant_id' => 'tenant-1',
    ]]);
    // authentication_methods left at the default: no entra_id.
    entraFakeHttp();

    Cache::put($state = Illuminate\Support\Str::uuid()->toString(), 'foo@bar.com', 60);

    $result = LoginService::call(code: 'code', state: $state);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['entra_id' => ['login_method_not_authorized']]);
});

it('returns an error when the domain is not configured with an integration', function (): void {
    entraFakeHttp();

    Cache::put($state = Illuminate\Support\Str::uuid()->toString(), 'foo@bar.com', 60);

    $result = LoginService::call(code: 'code', state: $state);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['base' => ['domain_not_configured']]);
});

it('returns entra_id_request_error when the token endpoint fails', function (): void {
    $integration = EntraIdIntegration::factory()->create(['settings' => [
        'client_id' => 'client_id',
        'domain' => 'bar.com',
        'tenant_id' => 'tenant-1',
    ]]);
    entraEnableLogin($integration);
    Http::fake(['https://login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response(['error' => 'invalid_client'], 401)]);

    Cache::put($state = Illuminate\Support\Str::uuid()->toString(), 'foo@bar.com', 60);

    $result = LoginService::call(code: 'code', state: $state);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['base' => ['entra_id_request_error']]);
});

it('does not create a new user when one already exists', function (): void {
    $integration = EntraIdIntegration::factory()->create(['settings' => [
        'client_id' => 'client_id',
        'domain' => 'bar.com',
        'tenant_id' => 'tenant-1',
    ]]);
    entraEnableLogin($integration);
    entraFakeHttp();

    $user = User::factory()->create(['email' => 'foo@bar.com']);

    Cache::put($state = Illuminate\Support\Str::uuid()->toString(), 'foo@bar.com', 60);

    LoginService::call(code: 'code', state: $state);

    expect(User::query()->where('email', 'foo@bar.com')->count())->toBe(1)
        ->and($user->fresh()->memberships()->where('organization_id', $integration->organization_id)->exists())->toBeTrue();
});

it('does not create a new membership when one already exists', function (): void {
    $integration = EntraIdIntegration::factory()->create(['settings' => [
        'client_id' => 'client_id',
        'domain' => 'bar.com',
        'tenant_id' => 'tenant-1',
    ]]);
    entraEnableLogin($integration);
    entraFakeHttp();

    $user = User::factory()->create(['email' => 'foo@bar.com']);
    Membership::factory()->for($user)->for($integration->organization)->create();

    Cache::put($state = Illuminate\Support\Str::uuid()->toString(), 'foo@bar.com', 60);

    LoginService::call(code: 'code', state: $state);

    expect(Membership::query()->where('user_id', $user->id)->count())->toBe(1);
});
