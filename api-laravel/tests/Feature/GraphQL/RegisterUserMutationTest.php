<?php

declare(strict_types=1);

require_once __DIR__.'/GraphQLHelpers.php';

use App\Models\Role;
use App\Models\BillingEntity;
use App\Support\Utils\AuthToken;
use App\Enums\EntityDocumentNumbering;

/**
 * Port of spec/graphql/mutations/register_user_spec.rb (Rails).
 *
 * Ledger row: gql:mutation:registerUser.
 */
const REGISTER_USER_MUTATION = <<<'GQL'
mutation($input: RegisterUserInput!) {
    registerUser(input: $input) {
        token
        user {
            id
            email
        }
        organization {
            id
            name
        }
        membership {
            id
        }
    }
}
GQL;

it('registers a new user with an organization, membership and token', function (): void {
    // Rails spec: `before { create(:role, :admin) }` — the global admin role
    // the new membership is attached to (seeded by BaseSeeder in dev).
    Role::create(['code' => 'admin', 'admin' => true, 'name' => 'Admin']);

    $response = gqlPost(REGISTER_USER_MUTATION, [
        'input' => [
            'email' => 'foo@bar.com',
            'password' => 'ILoveLago',
            'organizationName' => 'FooBar',
        ],
    ]);

    $response->assertOk();

    $resultData = $response->json('data.registerUser');

    expect($resultData['membership']['id'])->not->toBeEmpty()
        ->and($resultData['user']['email'])->toBe('foo@bar.com')
        ->and($resultData['organization']['name'])->toBe('FooBar')
        ->and($resultData['token'])->not->toBeEmpty();

    // The JWT carries the Rails login-method extra claim.
    $payload = AuthToken::decode($resultData['token']);

    expect($payload['login_method'])->toBe('email_password')
        ->and($payload['sub'])->toBe($resultData['user']['id']);

    // Organizations::CreateService tail: api key + default billing entity
    // sharing the organization's id.
    $organization = App\Models\Organization::query()->findOrFail($resultData['organization']['id']);

    expect($organization->apiKeys()->exists())->toBeTrue();

    $billingEntity = BillingEntity::query()->find($organization->id);

    expect($billingEntity)->not->toBeNull()
        ->and($billingEntity->code)->toBe('foobar')
        ->and($billingEntity->document_numbering)->toBe(EntityDocumentNumbering::PerBillingEntity);
})->group('ledger:gql:mutation:registerUser');

it('refuses an already existing email', function (): void {
    $user = gqlCreateUser();

    $response = gqlPost(REGISTER_USER_MUTATION, [
        'input' => [
            'email' => $user->email,
            'password' => 'ILoveLago',
            'organizationName' => 'FooBar',
        ],
    ]);

    $response->assertOk();

    $error = $response->json('errors.0');

    expect($error['extensions']['code'])->toBe('unprocessable_entity')
        ->and($error['extensions']['details'])->toHaveKey('email')
        ->and($error['extensions']['details']['email'])->toContain('user_already_exists');
})->group('ledger:gql:mutation:registerUser');

it('honours LAGO_DISABLE_SIGNUP', function (): void {
    config(['lago.signup_disabled' => true]);

    $response = gqlPost(REGISTER_USER_MUTATION, [
        'input' => [
            'email' => 'foo@bar.com',
            'password' => 'ILoveLago',
            'organizationName' => 'FooBar',
        ],
    ]);

    $error = $response->json('errors.0');

    expect($error['extensions']['status'])->toBe(405)
        ->and($error['extensions']['code'])->toBe('signup_disabled');
})->group('ledger:gql:mutation:registerUser');
