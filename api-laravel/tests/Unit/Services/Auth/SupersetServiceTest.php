<?php

declare(strict_types=1);

use App\Models\Organization;
use Illuminate\Support\Facades\Http;
use App\Services\Auth\SupersetService;

uses()->group('ledger:svc:Auth.SupersetService');

/**
 * Port of Rails' spec/services/auth/superset_service_spec.rb — the dashboards
 * listing flow: login → CSRF → list dashboards → per-dashboard embedded
 * config + guest token.
 */
function supersetConfig(): void
{
    config([
        'lago.superset.url' => 'http://localhost:8089',
        'lago.superset.username' => 'admin',
        'lago.superset.password' => 'admin',
    ]);
}

function supersetOrganization(): Organization
{
    return Organization::factory()->create(['name' => 'Test Org']);
}

function supersetHappyPath(): void
{
    Http::fake([
        '*/api/v1/security/login' => Http::response(['access_token' => 'access_token_123']),
        '*/api/v1/security/csrf_token/' => Http::response(['result' => 'csrf_token_456']),
        '*/api/v1/dashboard/' => Http::response([
            'result' => [
                ['id' => '1', 'dashboard_title' => 'Dashboard 1'],
                ['id' => '2', 'dashboard_title' => 'Dashboard 2'],
            ],
        ]),
        '*/api/v1/dashboard/1/embedded' => Http::response(['result' => ['uuid' => 'embedded-uuid-1']]),
        // Dashboard 2 has no embedded config yet: GET 404, POST creates it.
        '*/api/v1/dashboard/2/embedded' => Http::sequence()
            ->push(['' => []], 404)
            ->push(['result' => ['uuid' => 'embedded-uuid-2']]),
        '*/api/v1/security/guest_token/' => Http::response(['token' => 'guest_token_dashboard']),
    ]);
}

it('returns all dashboards with embedded config and guest tokens', function (): void {
    supersetConfig();
    supersetHappyPath();

    $organization = supersetOrganization();

    $result = SupersetService::call(organization: $organization);

    expect($result->failure())->toBeFalse()
        ->and($result->dashboards)->toHaveCount(2);

    $dashboard1 = collect($result->dashboards)->firstWhere('id', '1');
    $dashboard2 = collect($result->dashboards)->firstWhere('id', '2');

    expect($dashboard1['dashboard_title'])->toBe('Dashboard 1')
        ->and($dashboard1['embedded_id'])->toBe('embedded-uuid-1')
        ->and($dashboard1['guest_token'])->toBe('guest_token_dashboard')
        ->and($dashboard2['dashboard_title'])->toBe('Dashboard 2')
        ->and($dashboard2['embedded_id'])->toBe('embedded-uuid-2')
        ->and($dashboard2['guest_token'])->toBe('guest_token_dashboard');

    // The guest token mint is RLS-scoped to the organization.
    Http::assertSent(function ($request) use ($organization) {
        if (! str_contains($request->url(), '/api/v1/security/guest_token/')) {
            return false;
        }

        return str_contains(
            $request->body(),
            "organization_id = '".$organization->id."'",
        );
    });
});

it('fails with superset_missing_configuration when the env is incomplete', function (): void {
    config(['lago.superset.url' => null, 'lago.superset.username' => null, 'lago.superset.password' => null]);

    $result = SupersetService::call(organization: supersetOrganization());

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->code)->toBe('superset_missing_configuration');
});

it('fails with superset_auth_failed when the login returns 401', function (): void {
    supersetConfig();

    Http::fake(['*/api/v1/security/login' => Http::response('Invalid credentials', 401)]);

    $result = SupersetService::call(organization: supersetOrganization());

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->code)->toBe('superset_auth_failed');
});

it('fails with superset_auth_failed when no access token is returned', function (): void {
    supersetConfig();

    Http::fake(['*/api/v1/security/login' => Http::response([])]);

    $result = SupersetService::call(organization: supersetOrganization());

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->code)->toBe('superset_auth_failed');
});

it('fails with superset_csrf_failed when the CSRF call fails', function (): void {
    supersetConfig();

    Http::fake([
        '*/api/v1/security/login' => Http::response(['access_token' => 'access_token_123']),
        '*/api/v1/security/csrf_token/' => Http::response(['message' => 'Internal error'], 500),
    ]);

    $result = SupersetService::call(organization: supersetOrganization());

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->code)->toBe('superset_csrf_failed');
});

it('fails with superset_fetch_dashboards_failed when the dashboard list fails', function (): void {
    supersetConfig();

    Http::fake([
        '*/api/v1/security/login' => Http::response(['access_token' => 'access_token_123']),
        '*/api/v1/security/csrf_token/' => Http::response(['result' => 'csrf_token_456']),
        '*/api/v1/dashboard/' => Http::response(['message' => 'Internal error'], 500),
    ]);

    $result = SupersetService::call(organization: supersetOrganization());

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->code)->toBe('superset_fetch_dashboards_failed');
});

it('skips dashboards whose embedded config cannot be ensured', function (): void {
    supersetConfig();

    Http::fake([
        '*/api/v1/security/login' => Http::response(['access_token' => 'access_token_123']),
        '*/api/v1/security/csrf_token/' => Http::response(['result' => 'csrf_token_456']),
        '*/api/v1/dashboard/' => Http::response(['result' => [['id' => '1', 'dashboard_title' => 'Test']]]),
        // GET 404 then POST 500 — the embedded config cannot be created.
        '*/api/v1/dashboard/1/embedded' => Http::sequence()
            ->push([], 404)
            ->push(['message' => 'Failed to create'], 500),
    ]);

    $result = SupersetService::call(organization: supersetOrganization());

    expect($result->failure())->toBeFalse()
        ->and($result->dashboards)->toBe([]);
});

it('returns an empty dashboards list when none exist', function (): void {
    supersetConfig();

    Http::fake([
        '*/api/v1/security/login' => Http::response(['access_token' => 'access_token_123']),
        '*/api/v1/security/csrf_token/' => Http::response(['result' => 'csrf_token_456']),
        '*/api/v1/dashboard/' => Http::response(['result' => []]),
    ]);

    $result = SupersetService::call(organization: supersetOrganization());

    expect($result->failure())->toBeFalse()
        ->and($result->dashboards)->toBe([]);
});

it('uses the provided user info in the guest token', function (): void {
    supersetConfig();

    Http::fake([
        '*/api/v1/security/login' => Http::response(['access_token' => 'access_token_123']),
        '*/api/v1/security/csrf_token/' => Http::response(['result' => 'csrf_token_456']),
        '*/api/v1/dashboard/' => Http::response(['result' => [['id' => '1', 'dashboard_title' => 'Test']]]),
        '*/api/v1/dashboard/1/embedded' => Http::response(['result' => ['uuid' => 'embedded-uuid-1']]),
        '*/api/v1/security/guest_token/' => Http::response(['token' => 'guest_token']),
    ]);

    $result = SupersetService::call(
        organization: supersetOrganization(),
        user: ['first_name' => 'John', 'last_name' => 'Doe', 'username' => 'john.doe'],
    );

    expect($result->failure())->toBeFalse()
        ->and($result->dashboards)->toHaveCount(1);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/api/v1/security/guest_token/')) {
            return false;
        }

        $body = json_decode($request->body(), true);

        return $body['user'] === ['first_name' => 'John', 'last_name' => 'Doe', 'username' => 'john.doe'];
    });
});
