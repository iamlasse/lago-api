<?php

declare(strict_types=1);

uses()->group('ledger:svc:Auth.Okta.LoginService');

use App\Models\User;
use App\Models\Membership;
use Illuminate\Support\Env;
use App\Support\CurrentContext;
use App\Support\Utils\AuthToken;
use App\Services\Auth\Okta\LoginService;
use App\Models\Integrations\OktaIntegration;

/**
 * Port of spec/services/auth/okta/login_service_spec.rb (Rails) — the Okta
 * token/userinfo endpoints are stubbed with Http::fake() (Rails stubs
 * LagoHttpClient::Client).
 */
beforeEach(function (): void {
    CurrentContext::reset();

    // Rails' spec_helper.rb: ENV["LAGO_WEBHOOK_ALLOW_PRIVATE_URLS"] ||= "true".
    $_ENV['LAGO_WEBHOOK_ALLOW_PRIVATE_URLS'] = 'true';
    $_SERVER['LAGO_WEBHOOK_ALLOW_PRIVATE_URLS'] = 'true';
});

afterEach(function (): void {
    Env::getRepository()->clear('LAGO_WEBHOOK_ALLOW_PRIVATE_URLS');

    unset($_ENV['LAGO_WEBHOOK_ALLOW_PRIVATE_URLS'], $_SERVER['LAGO_WEBHOOK_ALLOW_PRIVATE_URLS']);
});

function oktaFakeHttp(array $tokenResponse = ['access_token' => 'access_token'], array $userinfoResponse = ['email' => 'foo@bar.com']): void
{
    Http::fake([
        'https://foo.okta.com/oauth2/v1/token' => Http::response($tokenResponse),
        'https://foo.okta.com/oauth2/v1/userinfo' => Http::response($userinfoResponse),
    ]);
}

function oktaEnableLogin(OktaIntegration $integration): void
{
    $organization = $integration->organization;
    $organization->premium_integrations = ['okta'];
    $organization->authentication_methods = ['email_password', 'okta'];
    $organization->save();
}

it('creates user, membership and authenticates the user', function (): void {
    $integration = OktaIntegration::factory()->create(['settings' => [
        'client_id' => 'client_id',
        'domain' => 'bar.com',
        'organization_name' => 'foo',
    ]]);
    oktaEnableLogin($integration);
    oktaFakeHttp();

    Cache::put($state = Illuminate\Support\Str::uuid()->toString(), 'foo@bar.com', 60);

    $result = LoginService::call(code: 'code', state: $state);

    expect($result->success())->toBeTrue()
        ->and($result->user->email)->toBe('foo@bar.com')
        ->and($result->token)->not->toBeEmpty();

    $decoded = AuthToken::decode($result->token);

    expect($decoded['login_method'])->toBe('okta')
        ->and($decoded['sub'])->toBe($result->user->id);

    expect($result->user->memberships()->where('organization_id', $integration->organization_id)->exists())->toBeTrue();
});

it('consumes the state exactly once', function (): void {
    $integration = OktaIntegration::factory()->create(['settings' => [
        'client_id' => 'client_id',
        'domain' => 'bar.com',
        'organization_name' => 'foo',
    ]]);
    oktaEnableLogin($integration);
    oktaFakeHttp();

    Cache::put($state = Illuminate\Support\Str::uuid()->toString(), 'foo@bar.com', 60);

    LoginService::call(code: 'code', state: $state);

    $second = LoginService::call(code: 'code', state: $state);

    expect($second->failure())->toBeTrue()
        ->and($second->getError()->messages)->toBe(['base' => ['state_not_found']]);
});

it('returns an error when the code is not provided', function (): void {
    oktaFakeHttp();

    Cache::put($state = Illuminate\Support\Str::uuid()->toString(), 'foo@bar.com', 60);

    $result = LoginService::call(code: '', state: $state);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['base' => ['code_not_found']]);
});

it('returns an error when the state is not provided', function (): void {
    oktaFakeHttp();

    $result = LoginService::call(code: 'code', state: '');

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['base' => ['state_not_found']]);
});

it('returns an error when the state is unknown', function (): void {
    oktaFakeHttp();

    $result = LoginService::call(code: 'code', state: Illuminate\Support\Str::uuid()->toString());

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['base' => ['state_not_found']]);
});

it('returns an error when the login method is not allowed', function (): void {
    $integration = OktaIntegration::factory()->create(['settings' => [
        'client_id' => 'client_id',
        'domain' => 'bar.com',
        'organization_name' => 'foo',
    ]]);
    // authentication_methods left at the default: no okta.
    oktaFakeHttp();

    Cache::put($state = Illuminate\Support\Str::uuid()->toString(), 'foo@bar.com', 60);

    $result = LoginService::call(code: 'code', state: $state);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['okta' => ['login_method_not_authorized']]);
});

it('returns an error when the domain is not configured with an integration', function (): void {
    oktaFakeHttp();

    Cache::put($state = Illuminate\Support\Str::uuid()->toString(), 'foo@bar.com', 60);

    $result = LoginService::call(code: 'code', state: $state);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['base' => ['domain_not_configured']]);
});

it('returns an error when the okta userinfo email differs from the state one', function (): void {
    $integration = OktaIntegration::factory()->create(['settings' => [
        'client_id' => 'client_id',
        'domain' => 'bar.com',
        'organization_name' => 'foo',
    ]]);
    oktaEnableLogin($integration);
    oktaFakeHttp(userinfoResponse: ['email' => 'foo@test.com']);

    Cache::put($state = Illuminate\Support\Str::uuid()->toString(), 'foo@bar.com', 60);

    $result = LoginService::call(code: 'code', state: $state);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['base' => ['okta_userinfo_error']]);
});

it('returns okta_request_error when the token endpoint fails', function (): void {
    $integration = OktaIntegration::factory()->create(['settings' => [
        'client_id' => 'client_id',
        'domain' => 'bar.com',
        'organization_name' => 'foo',
    ]]);
    oktaEnableLogin($integration);
    Http::fake(['https://foo.okta.com/oauth2/v1/token' => Http::response(['error' => 'invalid_client'], 401)]);

    Cache::put($state = Illuminate\Support\Str::uuid()->toString(), 'foo@bar.com', 60);

    $result = LoginService::call(code: 'code', state: $state);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['base' => ['okta_request_error']]);
});

it('does not create a new user when one already exists', function (): void {
    $integration = OktaIntegration::factory()->create(['settings' => [
        'client_id' => 'client_id',
        'domain' => 'bar.com',
        'organization_name' => 'foo',
    ]]);
    oktaEnableLogin($integration);
    oktaFakeHttp();

    $user = User::factory()->create(['email' => 'foo@bar.com']);

    Cache::put($state = Illuminate\Support\Str::uuid()->toString(), 'foo@bar.com', 60);

    LoginService::call(code: 'code', state: $state);

    expect(User::query()->where('email', 'foo@bar.com')->count())->toBe(1)
        ->and($user->fresh()->memberships()->where('organization_id', $integration->organization_id)->exists())->toBeTrue();
});

it('does not create a new membership when one already exists', function (): void {
    $integration = OktaIntegration::factory()->create(['settings' => [
        'client_id' => 'client_id',
        'domain' => 'bar.com',
        'organization_name' => 'foo',
    ]]);
    oktaEnableLogin($integration);
    oktaFakeHttp();

    $user = User::factory()->create(['email' => 'foo@bar.com']);
    Membership::factory()->for($user)->for($integration->organization)->create();

    Cache::put($state = Illuminate\Support\Str::uuid()->toString(), 'foo@bar.com', 60);

    LoginService::call(code: 'code', state: $state);

    expect(Membership::query()->where('user_id', $user->id)->count())->toBe(1);
});
