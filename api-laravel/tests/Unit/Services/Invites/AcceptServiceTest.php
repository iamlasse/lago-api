<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\Invite;
use App\Support\CurrentContext;
use App\Support\Utils\AuthToken;
use App\Services\Invites\AcceptService;
use App\Services\Failures\NotFoundFailure;
use App\Services\Failures\ValidationFailure;
use App\Support\Organizations\AuthenticationMethods;

/**
 * The Invites::AcceptService port itself (app/services/invites/
 * accept_service.rb) — the SSO services delegate here. Covers the guards the
 * Google/Okta/Entra variants do not hit directly.
 */
beforeEach(function (): void {
    CurrentContext::reset();

    Role::create([
        'code' => 'admin',
        'name' => 'Admin',
        'admin' => true,
        'permissions' => [],
        'organization_id' => null,
    ]);
});

it('accepts a pending invite by token and stamps the login method', function (): void {
    $invite = Invite::factory()->create(['email' => 'foo@bar.com']);

    $result = AcceptService::call(
        token: $invite->token,
        password: 'secret-password',
        loginMethod: AuthenticationMethods::GOOGLE_OAUTH,
    );

    expect($result->success())->toBeTrue()
        ->and($result->user->email)->toBe('foo@bar.com')
        ->and($result->membership->organization_id)->toBe($invite->organization_id)
        ->and(AuthToken::decode($result->token)['login_method'])->toBe('google_oauth')
        ->and($invite->fresh()->status->value)->toBe(1)
        ->and($invite->fresh()->membership_id)->toBe($result->membership->id);
});

it('returns a not found failure for an unknown token', function (): void {
    $result = AcceptService::call(token: 'nope', password: 'x');

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class)
        ->and($result->getError()->resource)->toBe('invite');
});

it('refuses a login method the organization does not allow', function (): void {
    $invite = Invite::factory()->create(['email' => 'foo@bar.com']);
    $invite->organization->update(['authentication_methods' => ['email_password']]);

    $result = AcceptService::call(
        invite: $invite,
        password: 'secret-password',
        loginMethod: AuthenticationMethods::OKTA,
    );

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages)->toBe(['okta' => ['login_method_not_authorized']]);
});
