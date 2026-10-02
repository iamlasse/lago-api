<?php

declare(strict_types=1);

require_once __DIR__.'/GraphQLHelpers.php';

use Firebase\JWT\JWT;
use App\Support\Utils\AuthToken;

/**
 * Ports of the AuthenticableUser controller-concern behaviours exercised by
 * Rails' request specs: JWT auth, x-lago-organization switch, sliding renewal
 * (x-lago-token), expired_jwt_token envelope — plus currentUser /
 * currentVersion resolvers.
 *
 * Ledger rows: gql:query:currentUser, gql:query:currentVersion.
 */
const CURRENT_USER_QUERY = <<<'GQL'
query {
    currentUser {
        id
        email
        csAdmin
        premium
        createdAt
        memberships {
            id
            status
            organization {
                id
                name
            }
        }
    }
}
GQL;

function gqlAuthHeaders(App\Models\User $user, ?string $organizationId = null): array
{
    $headers = ['Authorization' => 'Bearer '.AuthToken::encode($user)];

    if ($organizationId !== null) {
        $headers['x-lago-organization'] = $organizationId;
    }

    return $headers;
}

it('resolves currentUser from a Bearer JWT', function (): void {
    $organization = gqlCreateOrganization();
    $user = gqlCreateUser();
    gqlCreateMembership($user, $organization);

    $response = gqlPost(CURRENT_USER_QUERY, [], gqlAuthHeaders($user));

    $response->assertOk();

    $currentUser = $response->json('data.currentUser');

    expect($currentUser['id'])->toBe($user->id)
        ->and($currentUser['email'])->toBe($user->email)
        ->and($currentUser['csAdmin'])->toBeFalse()
        ->and($currentUser['premium'])->toBeFalse()
        // ISO8601DateTime leaves the API in UTC …Z
        ->and($currentUser['createdAt'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
        ->and($currentUser['memberships'])->toHaveCount(1)
        ->and($currentUser['memberships'][0]['status'])->toBe('active')
        ->and($currentUser['memberships'][0]['organization']['id'])->toBe($organization->id);
})->group('ledger:gql:query:currentUser');

it('only exposes active memberships on currentUser', function (): void {
    $organization = gqlCreateOrganization();
    $user = gqlCreateUser();
    gqlCreateMembership($user, $organization, 0);
    gqlCreateMembership($user, gqlCreateOrganization('Other Corp'), 1);

    $response = gqlPost(CURRENT_USER_QUERY, [], gqlAuthHeaders($user));

    expect($response->json('data.currentUser.memberships'))->toHaveCount(1)
        ->and($response->json('data.currentUser.memberships.0.organization.name'))->toBe('Acme Corp');
})->group('ledger:gql:query:currentUser');

it('returns unauthorized without a token', function (): void {
    $response = gqlPost(CURRENT_USER_QUERY);

    $error = $response->json('errors.0');

    expect($error['message'])->toBe('unauthorized')
        ->and($error['extensions'])->toBe([
            'status' => 'unauthorized',
            'code' => 'unauthorized',
        ]);
})->group('ledger:gql:query:currentUser');

it('treats an invalid token as unauthenticated outside local env', function (): void {
    $forged = JWT::encode(['sub' => 'nope', 'exp' => time() + 3600], 'other-secret-0123456789abcdef0123456789', 'HS256');

    $response = gqlPost(CURRENT_USER_QUERY, [], ['Authorization' => 'Bearer '.$forged]);

    expect($response->json('errors.0.extensions.code'))->toBe('unauthorized');
})->group('ledger:gql:query:currentUser');

it('renders the expired_jwt_token envelope for an expired token', function (): void {
    $organization = gqlCreateOrganization();
    $user = gqlCreateUser();
    gqlCreateMembership($user, $organization);

    $expired = JWT::encode(
        ['sub' => $user->id, 'exp' => time() - 10],
        (string) env('SECRET_KEY_BASE'),
        'HS256',
    );

    $response = gqlPost(CURRENT_USER_QUERY, [], ['Authorization' => 'Bearer '.$expired]);

    // Rails: render_graphql_error — HTTP 200 with a controller-level envelope.
    $response->assertOk();

    expect($response->json('data'))->toBe([])
        ->and($response->json('errors.0.message'))->toBe('expired_jwt_token')
        ->and($response->json('errors.0.extensions'))->toBe([
            'status' => 401,
            'code' => 'expired_jwt_token',
        ]);
})->group('ledger:gql:query:currentUser');

it('renews the token in the x-lago-token header when less than 1h remains', function (): void {
    $organization = gqlCreateOrganization();
    $user = gqlCreateUser();
    gqlCreateMembership($user, $organization);

    $nearExpiry = JWT::encode(
        ['sub' => $user->id, 'exp' => time() + 1800, 'login_method' => 'email_password'],
        (string) env('SECRET_KEY_BASE'),
        'HS256',
    );

    $response = gqlPost(CURRENT_USER_QUERY, [], ['Authorization' => 'Bearer '.$nearExpiry]);

    $response->assertOk();

    $renewed = $response->headers->get(AuthToken::LAGO_TOKEN_HEADER);

    expect($renewed)->not->toBeNull();

    $payload = AuthToken::decode($renewed);

    expect($payload['sub'])->toBe($user->id)
        ->and($payload['login_method'])->toBe('email_password')
        ->and($payload['exp'])->toBeGreaterThanOrEqual(time() + AuthToken::THREE_HOURS - 2);
})->group('ledger:gql:query:currentUser');

it('does not renew a token with more than 1h remaining', function (): void {
    $organization = gqlCreateOrganization();
    $user = gqlCreateUser();
    gqlCreateMembership($user, $organization);

    $response = gqlPost(CURRENT_USER_QUERY, [], gqlAuthHeaders($user));

    $response->assertOk();

    expect($response->headers->get(AuthToken::LAGO_TOKEN_HEADER))->toBeNull();
})->group('ledger:gql:query:currentUser');

it('retrieves the application version', function (): void {
    $response = gqlPost('query { currentVersion { githubUrl number } }');

    $response->assertOk();

    $currentVersion = $response->json('data.currentVersion');

    expect($currentVersion['githubUrl'])->toBe('https://github.com/getlago/lago-api')
        ->and($currentVersion['number'])->toBe(app()->environment());
})->group('ledger:gql:query:currentVersion');
