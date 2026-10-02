<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Membership;
use App\Models\Organization;
use Illuminate\Http\Request;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Exceptions\ExecutionError;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Execution\HttpGraphQLContext;

/**
 * Direct ports of the expectations in Rails' AuthenticableApiUser and
 * RequiredOrganization concerns (used via ready? by every resolver).
 */
function guardContext(?User $user = null, ?Organization $organization = null, ?Membership $membership = null): HttpGraphQLContext
{
    $request = Request::create('/graphql', 'POST');
    LagoContext::set($request, $user, $organization, $membership, null, null);

    return new HttpGraphQLContext($request);
}

function makeGuardUser(): User
{
    $user = new User;
    $user->id = '9f1aa9e0-0000-4000-8000-000000000001';
    $user->email = 'guard@example.com';

    return $user;
}

function makeGuardOrganization(): Organization
{
    $organization = new Organization;
    $organization->id = '9f1aa9e0-0000-4000-8000-000000000002';

    return $organization;
}

function makeGuardMembership(User $user, Organization $organization): Membership
{
    $membership = new Membership;
    $membership->user_id = $user->id;
    $membership->organization_id = $organization->id;

    return $membership;
}

it('raises unauthorized when AuthenticableApiUser has no current user', function (): void {
    try {
        AuthenticableApiUser::authorize(guardContext());
        $this->fail('Expected ExecutionError');
    } catch (ExecutionError $error) {
        expect($error->getMessage())->toBe('unauthorized')
            ->and($error->getExtensions())->toBe([
                'status' => 'unauthorized',
                'code' => 'unauthorized',
            ]);
    }
});

it('passes AuthenticableApiUser when a current user is present', function (): void {
    AuthenticableApiUser::authorize(guardContext(makeGuardUser()));

    expect(true)->toBeTrue();
});

it('raises forbidden with missing organization id without an organization', function (): void {
    $user = makeGuardUser();

    try {
        RequiredOrganization::authorize(guardContext($user));
        $this->fail('Expected ExecutionError');
    } catch (ExecutionError $error) {
        expect($error->getMessage())->toBe('Missing organization id')
            ->and($error->getExtensions())->toBe([
                'status' => 'forbidden',
                'code' => 'forbidden',
            ]);
    }
});

it('raises forbidden with missing membership without a membership', function (): void {
    $user = makeGuardUser();
    $organization = makeGuardOrganization();

    try {
        RequiredOrganization::authorize(guardContext($user, $organization));
        $this->fail('Expected ExecutionError');
    } catch (ExecutionError $error) {
        expect($error->getMessage())->toBe('Missing membership');
    }
});

it('raises forbidden when the membership does not link user and organization', function (): void {
    $user = makeGuardUser();
    $organization = makeGuardOrganization();
    $membership = makeGuardMembership($user, $organization);
    $membership->user_id = 'someone-else';

    try {
        RequiredOrganization::authorize(guardContext($user, $organization, $membership));
        $this->fail('Expected ExecutionError');
    } catch (ExecutionError $error) {
        expect($error->getMessage())->toBe('Not in organization')
            ->and($error->getExtensions())->toBe([
                'status' => 'forbidden',
                'code' => 'forbidden',
            ]);
    }
});

it('passes RequiredOrganization when user, organization and membership align', function (): void {
    $user = makeGuardUser();
    $organization = makeGuardOrganization();
    $membership = makeGuardMembership($user, $organization);

    RequiredOrganization::authorize(guardContext($user, $organization, $membership));

    expect(true)->toBeTrue();
});
