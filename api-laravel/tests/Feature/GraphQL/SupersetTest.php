<?php

declare(strict_types=1);

require_once __DIR__.'/GraphQLHelpers.php';

use App\Models\User;
use App\Support\Utils\AuthToken;
use Illuminate\Support\Facades\Http;

/**
 * Ports of Rails' spec/graphql/resolvers/superset/dashboards_resolver_spec.rb
 * and spec/graphql/mutations/superset/create_guest_token_spec.rb, over the
 * frozen SDL.
 *
 * TODO(port): Rails also requires the "analytics:view" permission on both
 * fields; the permission port is pending (graphql/FULL_SCHEMA_NOTES.md
 * item 3).
 */
const SUPERSET_DASHBOARDS_QUERY = <<<'GQL'
query {
    supersetDashboards {
        id
        dashboardTitle
        embeddedId
        guestToken
        supersetUrl
    }
}
GQL;

const SUPERSET_GUEST_TOKEN_MUTATION = <<<'GQL'
mutation($input: CreateSupersetGuestTokenInput!) {
    createSupersetGuestToken(input: $input) {
        guestToken
    }
}
GQL;

function gqlSupersetHeaders(User $user, ?string $organizationId): array
{
    return [
        'Authorization' => 'Bearer '.AuthToken::encode($user, extra: ['login_method' => 'email_password']),
        ...($organizationId !== null ? ['x-lago-organization' => $organizationId] : []),
    ];
}

function supersetStubbedHappyPath(): void
{
    config([
        'lago.superset.url' => 'http://localhost:8089',
        'lago.superset.username' => 'admin',
        'lago.superset.password' => 'admin',
    ]);

    Http::fake([
        '*/api/v1/security/login' => Http::response(['access_token' => 'access_token_123']),
        '*/api/v1/security/csrf_token/' => Http::response(['result' => 'csrf_token_456']),
        '*/api/v1/dashboard/' => Http::response([
            'result' => [['id' => '1', 'dashboard_title' => 'Sales Dashboard']],
        ]),
        '*/api/v1/dashboard/1/embedded' => Http::response(['result' => ['uuid' => 'embedded-uuid-1']]),
        '*/api/v1/security/guest_token/' => Http::response(['token' => 'guest-token-1']),
    ]);
}

it('requires a current user for supersetDashboards', function (): void {
    $organization = gqlCreateOrganization();
    $user = gqlCreateUser();
    gqlCreateMembership($user, $organization);

    $response = gqlPost(SUPERSET_DASHBOARDS_QUERY, [], ['x-lago-organization' => $organization->id]);

    $error = $response->json('errors.0');

    expect($error['extensions']['code'])->toBe('unauthorized');
});

it('requires a current organization for supersetDashboards', function (): void {
    $organization = gqlCreateOrganization();
    $user = gqlCreateUser();
    gqlCreateMembership($user, $organization);

    $response = gqlPost(SUPERSET_DASHBOARDS_QUERY, [], [
        'Authorization' => 'Bearer '.AuthToken::encode($user, extra: ['login_method' => 'email_password']),
    ]);

    $error = $response->json('errors.0');

    expect($error['extensions']['code'])->toBe('forbidden');
});

it('returns the list of superset dashboards', function (): void {
    supersetStubbedHappyPath();

    $organization = gqlCreateOrganization();
    $user = gqlCreateUser();
    gqlCreateMembership($user, $organization);

    $response = gqlPost(SUPERSET_DASHBOARDS_QUERY, [], gqlSupersetHeaders($user, $organization->id));

    $response->assertOk();

    $dashboards = $response->json('data.supersetDashboards');

    expect($dashboards)->toHaveCount(1)
        ->and($dashboards[0]['id'])->toBe('1')
        ->and($dashboards[0]['dashboardTitle'])->toBe('Sales Dashboard')
        ->and($dashboards[0]['embeddedId'])->toBe('embedded-uuid-1')
        ->and($dashboards[0]['guestToken'])->toBe('guest-token-1')
        ->and($dashboards[0]['supersetUrl'])->toBe('http://localhost:8089');
});

it('returns a service failure on the supersetDashboards query', function (): void {
    config(['lago.superset.url' => null, 'lago.superset.username' => null, 'lago.superset.password' => null]);

    $organization = gqlCreateOrganization();
    $user = gqlCreateUser();
    gqlCreateMembership($user, $organization);

    $response = gqlPost(SUPERSET_DASHBOARDS_QUERY, [], gqlSupersetHeaders($user, $organization->id));

    $error = $response->json('errors.0');

    expect($error['extensions']['code'])->toBe('superset_missing_configuration');
});

it('mints a fresh guest token for a single dashboard', function (): void {
    supersetStubbedHappyPath();

    $organization = gqlCreateOrganization();
    $user = gqlCreateUser();
    gqlCreateMembership($user, $organization);

    $response = gqlPost(
        SUPERSET_GUEST_TOKEN_MUTATION,
        ['input' => ['dashboardId' => '42']],
        gqlSupersetHeaders($user, $organization->id),
    );

    $response->assertOk();

    $payload = $response->json('data.createSupersetGuestToken');

    expect($payload['guestToken'])->toBe('guest-token-1');
});

it('returns a service failure on the guest token mutation', function (): void {
    config(['lago.superset.url' => null, 'lago.superset.username' => null, 'lago.superset.password' => null]);

    $organization = gqlCreateOrganization();
    $user = gqlCreateUser();
    gqlCreateMembership($user, $organization);

    $response = gqlPost(
        SUPERSET_GUEST_TOKEN_MUTATION,
        ['input' => ['dashboardId' => '42']],
        gqlSupersetHeaders($user, $organization->id),
    );

    $error = $response->json('errors.0');

    expect($error['extensions']['code'])->toBe('superset_missing_configuration');
});
