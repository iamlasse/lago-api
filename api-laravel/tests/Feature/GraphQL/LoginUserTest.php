<?php

declare(strict_types=1);

require_once __DIR__.'/GraphQLHelpers.php';

use App\Support\Utils\AuthToken;

/**
 * Port of spec/graphql/mutations/login_user_spec.rb (Rails).
 *
 * Ledger row: gql:mutation:loginUser.
 */
const LOGIN_USER_MUTATION = <<<'GQL'
mutation($input: LoginUserInput!) {
    loginUser(input: $input) {
        token
        user {
            id
            email
        }
    }
}
GQL;

it('returns token and user', function (): void {
    $organization = gqlCreateOrganization();
    $user = gqlCreateUser();
    gqlCreateMembership($user, $organization);

    $response = gqlPost(LOGIN_USER_MUTATION, [
        'input' => ['email' => $user->email, 'password' => 'ILoveLago'],
    ]);

    $response->assertOk();

    $resultData = $response->json('data.loginUser');

    expect($resultData['token'])->not->toBeEmpty()
        ->and($resultData['user']['id'])->toBe($user->id)
        ->and($resultData['user']['email'])->toBe($user->email);
})->group('ledger:gql:mutation:loginUser');

it('issues a Rails-compatible JWT carrying the sub and the login method', function (): void {
    $organization = gqlCreateOrganization();
    $user = gqlCreateUser();
    gqlCreateMembership($user, $organization);

    $response = gqlPost(LOGIN_USER_MUTATION, [
        'input' => ['email' => $user->email, 'password' => 'ILoveLago'],
    ]);

    $payload = AuthToken::decode($response->json('data.loginUser.token'));

    expect($payload['sub'])->toBe($user->id)
        ->and($payload['login_method'])->toBe('email_password')
        ->and($payload['exp'])->toBeGreaterThan(time());
})->group('ledger:gql:mutation:loginUser');

it('returns an error with bad credentials', function (): void {
    $organization = gqlCreateOrganization();
    $user = gqlCreateUser();
    gqlCreateMembership($user, $organization);

    $response = gqlPost(LOGIN_USER_MUTATION, [
        'input' => ['email' => $user->email, 'password' => 'badpassword'],
    ]);

    $response->assertOk();

    $error = $response->json('errors.0');

    expect($error['message'])->toBe('Unprocessable Entity')
        ->and($error['extensions']['status'])->toBe(422)
        ->and($error['extensions']['code'])->toBe('unprocessable_entity')
        ->and($error['extensions']['details'])->toHaveKey('base')
        ->and($error['extensions']['details']['base'])->toContain('incorrect_login_or_password');
})->group('ledger:gql:mutation:loginUser');

it('returns an error with a revoked membership', function (): void {
    $organization = gqlCreateOrganization();
    $user = gqlCreateUser();
    gqlCreateMembership($user, $organization, 1); // revoked

    $response = gqlPost(LOGIN_USER_MUTATION, [
        'input' => ['email' => $user->email, 'password' => 'ILoveLago'],
    ]);

    $error = $response->json('errors.0');

    expect($error['extensions']['code'])->toBe('unprocessable_entity')
        ->and($error['extensions']['details']['base'])->toContain('incorrect_login_or_password');
})->group('ledger:gql:mutation:loginUser');

it('returns an error with an unknown email', function (): void {
    $response = gqlPost(LOGIN_USER_MUTATION, [
        'input' => ['email' => 'ghost@example.com', 'password' => 'ILoveLago'],
    ]);

    expect($response->json('errors.0.extensions.details.base'))->toContain('incorrect_login_or_password');
})->group('ledger:gql:mutation:loginUser');

it('survives null bytes in credentials', function (): void {
    $response = gqlPost(LOGIN_USER_MUTATION, [
        'input' => ['email' => "email@example.com\u{0000}", 'password' => "ILoveLago\u{0000}"],
    ]);

    expect($response->json('errors.0.extensions.details.base'))->toContain('incorrect_login_or_password');
})->group('ledger:gql:mutation:loginUser');
