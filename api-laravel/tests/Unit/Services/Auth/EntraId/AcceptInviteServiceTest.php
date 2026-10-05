<?php

declare(strict_types=1);

uses()->group('ledger:svc:Auth.EntraId.AcceptInviteService',
    'ledger:svc:Auth.EntraId.BaseService');

use App\Models\Role;
use App\Models\Invite;
use App\Support\CurrentContext;
use App\Support\Utils\AuthToken;
use App\Models\Integrations\EntraIdIntegration;
use App\Services\Auth\EntraId\AcceptInviteService;

/**
 * Port of spec/services/auth/entra_id/accept_invite_service_spec.rb (Rails).
 */
beforeEach(function (): void {
    CurrentContext::reset();

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

function entraInviteFixtures(string $userinfoEmail = 'foo@bar.com'): array
{
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
        'https://graph.microsoft.com/oidc/userinfo' => Http::response(['email' => $userinfoEmail]),
    ]);

    Cache::put($state = Illuminate\Support\Str::uuid()->toString(), 'foo@bar.com', 60);

    return [$invite, $state];
}

it('creates the user, marks the invite as accepted and authenticates the user', function (): void {
    [$invite, $state] = entraInviteFixtures();

    $result = AcceptInviteService::call(inviteToken: $invite->token, code: 'code', state: $state);

    expect($result->success())->toBeTrue()
        ->and($result->user->email)->toBe('foo@bar.com')
        ->and($result->token)->not->toBeEmpty()
        ->and($invite->fresh()->status->value)->toBe(1); // accepted

    $decoded = AuthToken::decode($result->token);

    expect($decoded['login_method'])->toBe('entra_id');
});

it('returns an error when the pending invite does not exist', function (): void {
    [$invite, $state] = entraInviteFixtures();
    $accepted = Invite::factory()->accepted()->create(['email' => 'foo@bar.com', 'organization_id' => $invite->organization_id]);

    $result = AcceptInviteService::call(inviteToken: $accepted->token, code: 'code', state: $state);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['base' => ['invite_not_found']]);
});

it('returns an error when the userinfo email differs from the state one', function (): void {
    [$invite, $state] = entraInviteFixtures(userinfoEmail: 'foo@test.com');

    $result = AcceptInviteService::call(inviteToken: $invite->token, code: 'code', state: $state);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['base' => ['entra_id_userinfo_error']]);
});
