<?php

declare(strict_types=1);

use App\Models\Organization;
use Illuminate\Support\Facades\Http;
use App\Services\Auth\Superset\GuestTokenService;

uses()->group('ledger:svc:Auth.Superset.GuestTokenService');

/**
 * Port of Rails' spec/services/auth/superset/guest_token_service_spec.rb —
 * a single fresh guest token for one dashboard, RLS-scoped to the
 * organization.
 */
function guestSupersetConfig(): void
{
    config([
        'lago.superset.url' => 'http://localhost:8089',
        'lago.superset.username' => 'admin',
        'lago.superset.password' => 'admin',
    ]);
}

function guestSupersetAuth(): void
{
    Http::fake([
        '*/api/v1/security/login' => Http::response(['access_token' => 'access_token_123']),
        '*/api/v1/security/csrf_token/' => Http::response(['result' => 'csrf_token_456']),
    ]);
}

it('mints a guest token scoped to the organization', function (): void {
    guestSupersetConfig();

    $organization = Organization::factory()->create(['name' => 'Test Org']);

    Http::fake([
        '*/api/v1/security/login' => Http::response(['access_token' => 'access_token_123']),
        '*/api/v1/security/csrf_token/' => Http::response(['result' => 'csrf_token_456']),
        '*/api/v1/security/guest_token/' => Http::response(['token' => 'guest_token_for_dashboard']),
    ]);

    $result = GuestTokenService::call(organization: $organization, dashboardId: '42');

    expect($result->failure())->toBeFalse()
        ->and($result->guest_token)->toBe('guest_token_for_dashboard');

    Http::assertSent(function ($request) use ($organization) {
        if (! str_contains($request->url(), '/api/v1/security/guest_token/')) {
            return false;
        }

        $body = json_decode($request->body(), true);

        return $body['resources'] === [['id' => '42', 'type' => 'dashboard']]
            && $body['rls'] === [['clause' => "organization_id = '".$organization->id."'"]];
    });
});

it('uses the provided user info', function (): void {
    guestSupersetConfig();

    Http::fake([
        '*/api/v1/security/login' => Http::response(['access_token' => 'access_token_123']),
        '*/api/v1/security/csrf_token/' => Http::response(['result' => 'csrf_token_456']),
        '*/api/v1/security/guest_token/' => Http::response(['token' => 'guest_token_for_dashboard']),
    ]);

    $result = GuestTokenService::call(
        organization: Organization::factory()->create(),
        dashboardId: '42',
        user: ['first_name' => 'John', 'last_name' => 'Doe', 'username' => 'john.doe'],
    );

    expect($result->failure())->toBeFalse()
        ->and($result->guest_token)->toBe('guest_token_for_dashboard');

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/api/v1/security/guest_token/')) {
            return false;
        }

        $body = json_decode($request->body(), true);

        return $body['user'] === ['first_name' => 'John', 'last_name' => 'Doe', 'username' => 'john.doe'];
    });
});

it('fails with superset_auth_failed when the login fails', function (): void {
    guestSupersetConfig();

    Http::fake([
        '*/api/v1/security/login' => Http::response('Invalid credentials', 401),
    ]);

    $result = GuestTokenService::call(organization: Organization::factory()->create(), dashboardId: '42');

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->code)->toBe('superset_auth_failed');
});

it('fails with superset_csrf_failed when the CSRF call fails', function (): void {
    guestSupersetConfig();

    Http::fake([
        '*/api/v1/security/login' => Http::response(['access_token' => 'access_token_123']),
        '*/api/v1/security/csrf_token/' => Http::response(['message' => 'Internal error'], 500),
    ]);

    $result = GuestTokenService::call(organization: Organization::factory()->create(), dashboardId: '42');

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->code)->toBe('superset_csrf_failed');
});

it('fails with superset_guest_token_failed when no token comes back', function (): void {
    guestSupersetConfig();
    guestSupersetAuth();

    Http::fake(['*/api/v1/security/guest_token/' => Http::response([])]);

    $result = GuestTokenService::call(organization: Organization::factory()->create(), dashboardId: '42');

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->code)->toBe('superset_guest_token_failed');
});

it('fails with superset_missing_configuration when the env is incomplete', function (): void {
    config(['lago.superset.url' => null, 'lago.superset.username' => null, 'lago.superset.password' => null]);

    $result = GuestTokenService::call(organization: Organization::factory()->create(), dashboardId: '42');

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->code)->toBe('superset_missing_configuration');
});
