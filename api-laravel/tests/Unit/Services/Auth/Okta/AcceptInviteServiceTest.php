<?php

declare(strict_types=1);

uses()->group('ledger:svc:Auth.Okta.AcceptInviteService',
    'ledger:svc:Auth.Okta.BaseService');

use App\Models\Role;
use App\Models\Invite;
use App\Support\CurrentContext;
use App\Support\Utils\AuthToken;
use App\Models\Integrations\OktaIntegration;
use App\Services\Auth\Okta\AcceptInviteService;

/**
 * Port of spec/services/auth/okta/accept_invite_service_spec.rb (Rails).
 */
beforeEach(function (): void {
    CurrentContext::reset();

    // Rails' spec_helper.rb: ENV["LAGO_WEBHOOK_ALLOW_PRIVATE_URLS"] ||= "true".
    $_ENV['LAGO_WEBHOOK_ALLOW_PRIVATE_URLS'] = 'true';
    $_SERVER['LAGO_WEBHOOK_ALLOW_PRIVATE_URLS'] = 'true';

    // Rails: create(:role, :admin) — invite.roles = ["admin"].
    Role::create([
        'code' => 'admin',
        'name' => 'Admin',
        'admin' => true,
        'permissions' => [],
        'organization_id' => null,
    ]);
});

afterEach(function (): void {
    unset($_ENV['LAGO_WEBHOOK_ALLOW_PRIVATE_URLS'], $_SERVER['LAGO_WEBHOOK_ALLOW_PRIVATE_URLS']);
});

function oktaInviteFixtures(string $userinfoEmail = 'foo@bar.com'): array
{
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
        'https://foo.okta.com/oauth2/v1/userinfo' => Http::response(['email' => $userinfoEmail]),
    ]);

    Cache::put($state = Illuminate\Support\Str::uuid()->toString(), 'foo@bar.com', 60);

    return [$invite, $state];
}

it('creates the user, marks the invite as accepted and authenticates the user', function (): void {
    [$invite, $state] = oktaInviteFixtures();

    $result = AcceptInviteService::call(inviteToken: $invite->token, code: 'code', state: $state);

    expect($result->success())->toBeTrue()
        ->and($result->user->email)->toBe('foo@bar.com')
        ->and($result->token)->not->toBeEmpty()
        ->and($invite->fresh()->status->value)->toBe(1); // accepted

    $decoded = AuthToken::decode($result->token);

    expect($decoded['login_method'])->toBe('okta');
});

it('returns an error when the code is not provided', function (): void {
    [$invite, $state] = oktaInviteFixtures();

    $result = AcceptInviteService::call(inviteToken: $invite->token, code: '', state: $state);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['base' => ['code_not_found']]);
});

it('returns an error when the state is not found', function (): void {
    [$invite] = oktaInviteFixtures();

    $result = AcceptInviteService::call(
        inviteToken: $invite->token,
        code: 'code',
        state: Illuminate\Support\Str::uuid()->toString(),
    );

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['base' => ['state_not_found']]);
});

it('returns an error when the domain is not configured with an integration', function (): void {
    [$invite] = oktaInviteFixtures();
    App\Models\Integration::query()->delete();

    Cache::put($state = Illuminate\Support\Str::uuid()->toString(), 'foo@bar.com', 60);

    $result = AcceptInviteService::call(inviteToken: $invite->token, code: 'code', state: $state);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['base' => ['domain_not_configured']]);
});

it('returns an error when the pending invite does not exist', function (): void {
    [$invite, $state] = oktaInviteFixtures();
    $accepted = Invite::factory()->accepted()->create(['email' => 'foo@bar.com', 'organization_id' => $invite->organization_id]);

    $result = AcceptInviteService::call(inviteToken: $accepted->token, code: 'code', state: $state);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['base' => ['invite_not_found']]);
});

it('returns an error when the okta userinfo email differs from the state one', function (): void {
    [$invite, $state] = oktaInviteFixtures(userinfoEmail: 'foo@test.com');

    $result = AcceptInviteService::call(inviteToken: $invite->token, code: 'code', state: $state);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['base' => ['okta_userinfo_error']]);
});
