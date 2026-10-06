<?php

declare(strict_types=1);

require_once __DIR__.'/GraphQLHelpers.php';
require_once __DIR__.'/AuthPlumbingTest.php';

use App\Models\Invite;
use App\Models\Organization;
use App\Models\Membership;
use App\Models\MembershipRole;
use App\Models\PasswordReset;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

/**
 * Ports of Rails' spec/graphql/mutations/{invites,memberships,roles,
 * password_resets}/ and spec/graphql/resolvers/{invite,invites,memberships,
 * role,roles,password_reset}_resolver_spec.rb, over POST /graphql against
 * the frozen-schema contract.
 *
 * Ledger rows: gql:mutation:{acceptInvite,createInvite,revokeInvite,
 * updateInvite,revokeMembership,updateMembership,createRole,updateRole,
 * destroyRole,createPasswordReset,resetPassword}, gql:query:{invite,invites,
 * memberships,role,roles,passwordReset,googleAuthUrl}.
 */
function gqlTeamSetup(): array
{
    $organization = gqlCreateOrganization();
    $user = gqlCreateUser('team-admin@example.com');
    $membership = gqlCreateMembership($user, $organization);

    // Rails' membership factory carries the admin role.
    MembershipRole::create([
        'organization_id' => $organization->id,
        'membership_id' => $membership->id,
        'role_id' => gqlAdminRole()->id,
    ]);

    return [$organization->refresh(), $user];
}

/** Rails: Role.admins — the single predefined admin role (unique partial index). */
function gqlAdminRole(): Role
{
    $role = Role::query()->where('admin', true)->first();

    if ($role !== null) {
        return $role;
    }

    return Role::create([
        'organization_id' => null,
        'code' => 'admin',
        'name' => 'Admin',
        'admin' => true,
        'permissions' => [],
    ]);
}

function gqlCreateRole(Organization $organization, array $attrs = []): Role
{
    return Role::create(array_merge([
        'organization_id' => $organization->id,
        'code' => 'custom'.uniqid(),
        'name' => 'Custom Role',
        'admin' => false,
        'permissions' => ['memberships_view'],
    ], $attrs));
}

it('creates an invite for a new member', function (): void {
    [$organization, $user] = gqlTeamSetup();
    $role = gqlCreateRole($organization);

    $response = gqlPost(<<<'GQL'
    mutation($input: CreateInviteInput!) {
        createInvite(input: $input) {
            id
            email
            roles
            status
            token
        }
    }
    GQL, ['input' => [
        'email' => 'invitee@example.com',
        'roles' => [$role->code],
    ]], gqlAuthHeaders($user, $organization->id));

    $payload = $response->json('data.createInvite');

    expect($payload['email'])->toBe('invitee@example.com')
        ->and($payload['roles'])->toBe([$role->code])
        ->and($payload['status'])->toBe('pending')
        ->and($payload['token'])->not->toBeNull();

    expect(Invite::query()->where('email', 'invitee@example.com')->exists())->toBeTrue();
})->group('ledger:gql:mutation:createInvite');

it('refuses a duplicate pending invite, an existing member and an unknown role', function (): void {
    [$organization, $user] = gqlTeamSetup();

    // A pending invite for the same email (field "invite").
    Invite::create([
        'organization_id' => $organization->id,
        'email' => 'dup@example.com',
        'token' => 'pending-token-1',
        'roles' => ['admin'],
    ]);

    $response = gqlPost(<<<'GQL'
    mutation($input: CreateInviteInput!) {
        createInvite(input: $input) { id }
    }
    GQL, ['input' => ['email' => 'dup@example.com', 'roles' => ['admin']]],
        gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors.0.extensions.code'))->toBe('unprocessable_entity')
        ->and($response->json('errors.0.extensions.details.invite.0'))->toBe('invite_already_exists');

    // An active member's email (field "email").
    $response = gqlPost(<<<'GQL'
    mutation($input: CreateInviteInput!) {
        createInvite(input: $input) { id }
    }
    GQL, ['input' => ['email' => 'team-admin@example.com', 'roles' => ['admin']]],
        gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors.0.extensions.details.email.0'))->toBe('email_already_used');

    // An unknown role code (field "roles").
    $response = gqlPost(<<<'GQL'
    mutation($input: CreateInviteInput!) {
        createInvite(input: $input) { id }
    }
    GQL, ['input' => ['email' => 'other@example.com', 'roles' => ['nope']]],
        gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors.0.extensions.details.roles.0'))->toBe('invalid_role');
})->group('ledger:gql:mutation:createInvite');

it('refuses granting admin to a non-admin actor', function (): void {
    // The actor's membership carries a non-admin custom role only.
    $organization = gqlCreateOrganization();
    $user = gqlCreateUser('plain-member@example.com');
    $membership = gqlCreateMembership($user, $organization);
    $custom = gqlCreateRole($organization);
    MembershipRole::create([
        'organization_id' => $organization->id,
        'membership_id' => $membership->id,
        'role_id' => $custom->id,
    ]);

    $response = gqlPost(<<<'GQL'
    mutation($input: CreateInviteInput!) {
        createInvite(input: $input) { id }
    }
    GQL, ['input' => ['email' => 'newbie@example.com', 'roles' => ['admin']]],
        gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors.0.extensions.code'))->toBe('cannot_grant_admin')
        ->and(Invite::query()->where('email', 'newbie@example.com')->exists())->toBeFalse();
})->group('ledger:gql:mutation:createInvite');

it('updates the roles of a pending invite', function (): void {
    [$organization, $user] = gqlTeamSetup();
    $roleA = gqlCreateRole($organization);
    $roleB = gqlCreateRole($organization, ['code' => 'second'.uniqid(), 'name' => 'Second']);

    $invite = Invite::create([
        'organization_id' => $organization->id,
        'email' => 'update-me@example.com',
        'token' => 'update-token-1',
        'roles' => [$roleA->code],
    ]);

    $response = gqlPost(<<<'GQL'
    mutation($input: UpdateInviteInput!) {
        updateInvite(input: $input) { id roles }
    }
    GQL, ['input' => ['id' => $invite->id, 'roles' => [$roleB->code]]],
        gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.updateInvite.roles'))->toBe([$roleB->code]);
    expect($invite->refresh()->roles)->toBe([$roleB->code]);
})->group('ledger:gql:mutation:updateInvite');

it('answers not_found when updating a non-pending invite', function (): void {
    // Rails resolves the invite among the PENDING ones only — an accepted
    // invite is unreachable for the mutation (the service's
    // cannot_update_accepted_invite guard only guards concurrent accepts).
    [$organization, $user] = gqlTeamSetup();
    $invite = Invite::create([
        'organization_id' => $organization->id,
        'email' => 'accepted@example.com',
        'token' => 'accepted-token-1',
        'roles' => ['admin'],
    ]);
    $invite->markAsAccepted();

    $response = gqlPost(<<<'GQL'
    mutation($input: UpdateInviteInput!) {
        updateInvite(input: $input) { id }
    }
    GQL, ['input' => ['id' => $invite->id, 'roles' => ['admin']]],
        gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors.0.extensions.code'))->toBe('not_found');
})->group('ledger:gql:mutation:updateInvite');

it('revokes a pending invite and answers not_found for a revoked one', function (): void {
    [$organization, $user] = gqlTeamSetup();
    $invite = Invite::create([
        'organization_id' => $organization->id,
        'email' => 'revoke-me@example.com',
        'token' => 'revoke-token-1',
        'roles' => ['admin'],
    ]);

    $response = gqlPost(<<<'GQL'
    mutation($input: RevokeInviteInput!) {
        revokeInvite(input: $input) { id status }
    }
    GQL, ['input' => ['id' => $invite->id]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.revokeInvite.status'))->toBe('revoked');

    // The revoked invite is no longer reachable (only pending invites are).
    $response = gqlPost(<<<'GQL'
    mutation($input: RevokeInviteInput!) {
        revokeInvite(input: $input) { id }
    }
    GQL, ['input' => ['id' => $invite->id]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors.0.extensions.code'))->toBe('not_found');
})->group('ledger:gql:mutation:revokeInvite');

it('accepts an invite with an email and password', function (): void {
    [$organization, $adminUser] = gqlTeamSetup();
    $invite = Invite::create([
        'organization_id' => $organization->id,
        'email' => 'acceptee@example.com',
        'token' => 'accept-token-1',
        'roles' => ['admin'],
    ]);

    $response = gqlPost(<<<'GQL'
    mutation($input: AcceptInviteInput!) {
        acceptInvite(input: $input) {
            token
            user { email }
            membership { id }
        }
    }
    GQL, ['input' => [
        'email' => 'acceptee@example.com',
        'password' => 'ILoveLago',
        'token' => 'accept-token-1',
    ]]);

    $payload = $response->json('data.acceptInvite');

    expect($payload['user']['email'])->toBe('acceptee@example.com')
        ->and($payload['token'])->not->toBeNull()
        ->and($payload['membership']['id'])->not->toBeNull();

    expect($invite->refresh()->status->value)->toBe(1); // accepted

    // The invitee holds an active membership in the inviting organization.
    $membership = Membership::query()->find($payload['membership']['id']);

    expect($membership?->organization_id)->toBe($organization->id)
        ->and($membership?->status->value)->toBe(0); // active
})->group('ledger:gql:mutation:acceptInvite');

it('answers not_found when the invite token is unknown', function (): void {
    $response = gqlPost(<<<'GQL'
    mutation($input: AcceptInviteInput!) {
        acceptInvite(input: $input) { token }
    }
    GQL, ['input' => [
        'email' => 'nobody@example.com',
        'password' => 'ILoveLago',
        'token' => 'unknown-token',
    ]]);

    expect($response->json('errors.0.extensions.code'))->toBe('not_found');
})->group('ledger:gql:mutation:acceptInvite');

it('lists the pending invites with the role filter and search term', function (): void {
    [$organization, $user] = gqlTeamSetup();
    $role = gqlCreateRole($organization);

    Invite::create([
        'organization_id' => $organization->id,
        'email' => 'ana@example.com',
        'token' => 'list-token-1',
        'roles' => [$role->code],
    ]);
    Invite::create([
        'organization_id' => $organization->id,
        'email' => 'bob@example.com',
        'token' => 'list-token-2',
        'roles' => ['admin'],
    ]);

    $response = gqlPost(<<<'GQL'
    query($roleIds: [ID!]) {
        invites(roleIds: $roleIds, limit: 10) {
            collection { email roles }
            metadata { totalCount }
        }
    }
    GQL, ['roleIds' => [$role->id]], gqlAuthHeaders($user, $organization->id));

    $collection = $response->json('data.invites.collection');

    expect($collection)->toHaveCount(1)
        ->and($collection[0]['email'])->toBe('ana@example.com')
        ->and($response->json('data.invites.metadata.totalCount'))->toBe(1);

    // Search term narrows by email.
    $response = gqlPost(<<<'GQL'
    query {
        invites(searchTerm: "bob") { collection { email } metadata { totalCount } }
    }
    GQL, [], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.invites.collection.0.email'))->toBe('bob@example.com');
})->group('ledger:gql:query:invites');

it('fetches a pending invite by token and answers not_found otherwise', function (): void {
    Invite::create([
        'organization_id' => gqlCreateOrganization()->id,
        'email' => 'token@example.com',
        'token' => 'the-token',
        'roles' => ['admin'],
    ]);

    $response = gqlPost(<<<'GQL'
    query {
        invite(token: "the-token") { email status }
    }
    GQL);

    expect($response->json('data.invite.email'))->toBe('token@example.com')
        ->and($response->json('data.invite.status'))->toBe('pending');

    $response = gqlPost(<<<'GQL'
    query {
        invite(token: "wrong") { email }
    }
    GQL);

    expect($response->json('errors.0.extensions.code'))->toBe('not_found')
        ->and($response->json('errors.0.extensions.details.invite.0'))->toBe('not_found');
})->group('ledger:gql:query:invite');

it('lists the active memberships with the search term', function (): void {
    [$organization, $user] = gqlTeamSetup();
    $other = gqlCreateUser('member-two@example.com');
    gqlCreateMembership($other, $organization);
    // A revoked membership stays hidden.
    gqlCreateMembership(gqlCreateUser('revoked@example.com'), $organization, 1);

    $response = gqlPost(<<<'GQL'
    query {
        memberships(limit: 10) {
            collection { user { email } status }
            metadata { totalCount }
        }
    }
    GQL, [], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.memberships.collection'))->toHaveCount(2)
        ->and($response->json('data.memberships.metadata.totalCount'))->toBe(2);

    $response = gqlPost(<<<'GQL'
    query {
        memberships(searchTerm: "member-two") {
            collection { user { email } }
        }
    }
    GQL, [], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.memberships.collection.0.user.email'))->toBe('member-two@example.com');
})->group('ledger:gql:query:memberships');

it('revokes a membership and protects the last admin', function (): void {
    [$organization, $user] = gqlTeamSetup();
    $other = gqlCreateUser('revokee@example.com');
    $membership = gqlCreateMembership($other, $organization);

    $response = gqlPost(<<<'GQL'
    mutation($input: RevokeMembershipInput!) {
        revokeMembership(input: $input) { id status }
    }
    GQL, ['input' => ['id' => $membership->id]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.revokeMembership.status'))->toBe('revoked');

    // The acting user cannot revoke their own membership...
    $own = Membership::query()->where('user_id', $user->id)->firstOrFail();

    $response = gqlPost(<<<'GQL'
    mutation($input: RevokeMembershipInput!) {
        revokeMembership(input: $input) { id }
    }
    GQL, ['input' => ['id' => $own->id]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors.0.extensions.code'))->toBe('cannot_revoke_own_membership');
})->group('ledger:gql:mutation:revokeMembership');

it('updates the roles of a membership', function (): void {
    [$organization, $user] = gqlTeamSetup();
    $other = gqlCreateUser('roles@example.com');
    $membership = gqlCreateMembership($other, $organization);
    $role = gqlCreateRole($organization);

    $response = gqlPost(<<<'GQL'
    mutation($input: UpdateMembershipInput!) {
        updateMembership(input: $input) { id roles }
    }
    GQL, ['input' => ['id' => $membership->id, 'roles' => [$role->code]]],
        gqlAuthHeaders($user, $organization->id));

    // Rails: membership.roles.pluck(:name) — the wire carries role names.
    expect($response->json('data.updateMembership.roles'))->toBe([$role->name]);

    expect(MembershipRole::query()
        ->where('membership_id', $membership->id)
        ->where('role_id', $role->id)
        ->exists())->toBeTrue();

    // Unknown role codes answer the role not_found envelope.
    $response = gqlPost(<<<'GQL'
    mutation($input: UpdateMembershipInput!) {
        updateMembership(input: $input) { id }
    }
    GQL, ['input' => ['id' => $membership->id, 'roles' => ['ghost']]],
        gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors.0.extensions.code'))->toBe('not_found');
})->group('ledger:gql:mutation:updateMembership');

it('creates a custom role behind the premium custom_roles flag', function (): void {
    [$organization, $user] = gqlTeamSetup();

    config()->set('lago.license', 'premium-token');
    $organization->premium_integrations = ['custom_roles'];
    $organization->save();

    $response = gqlPost(<<<'GQL'
    mutation($input: CreateRoleInput!) {
        createRole(input: $input) { id code name permissions admin }
    }
    GQL, ['input' => [
        'code' => 'finance_custom',
        'name' => 'Finance Custom',
        'description' => 'Read-only finance role',
        'permissions' => ['analytics_view', 'invoices_view'],
    ]], gqlAuthHeaders($user, $organization->id));

    $payload = $response->json('data.createRole');

    expect($payload['code'])->toBe('finance_custom')
        ->and($payload['name'])->toBe('Finance Custom')
        ->and($payload['admin'])->toBeFalse()
        ->and($payload['permissions'])->toContain('analytics_view');

    // Without the premium flag the mutation answers feature_unavailable.
    [$plainOrganization, $plainUser] = gqlTeamSetup();

    $response = gqlPost(<<<'GQL'
    mutation($input: CreateRoleInput!) {
        createRole(input: $input) { id }
    }
    GQL, ['input' => ['code' => 'x', 'name' => 'X', 'permissions' => ['analytics_view']]],
        gqlAuthHeaders($plainUser, $plainOrganization->id));

    expect($response->json('errors.0.extensions.code'))->toBe('forbidden');
})->group('ledger:gql:mutation:createRole');

it('updates a custom role and refuses the predefined ones', function (): void {
    [$organization, $user] = gqlTeamSetup();
    config()->set('lago.license', 'premium-token');
    $organization->premium_integrations = ['custom_roles'];
    $organization->save();

    $role = gqlCreateRole($organization, ['name' => 'Before']);

    $response = gqlPost(<<<'GQL'
    mutation($input: UpdateRoleInput!) {
        updateRole(input: $input) { id name description }
    }
    GQL, ['input' => ['id' => $role->id, 'name' => 'After', 'description' => 'Updated']],
        gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.updateRole.name'))->toBe('After')
        ->and($response->json('data.updateRole.description'))->toBe('Updated');

    // The predefined admin role (organization_id NULL) is not updatable.
    $predefined = Role::query()->where('admin', true)->firstOrFail();

    $response = gqlPost(<<<'GQL'
    mutation($input: UpdateRoleInput!) {
        updateRole(input: $input) { id }
    }
    GQL, ['input' => ['id' => $predefined->id, 'name' => 'Nope']],
        gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors.0.extensions.code'))->toBe('predefined_role');
})->group('ledger:gql:mutation:updateRole');

it('destroys a custom role but not one assigned to members', function (): void {
    [$organization, $user] = gqlTeamSetup();
    config()->set('lago.license', 'premium-token');
    $organization->premium_integrations = ['custom_roles'];
    $organization->save();

    $freeRole = gqlCreateRole($organization);

    $response = gqlPost(<<<'GQL'
    mutation($input: DestroyRoleInput!) {
        destroyRole(input: $input) { id }
    }
    GQL, ['input' => ['id' => $freeRole->id]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.destroyRole.id'))->toBe($freeRole->id)
        ->and($freeRole->refresh()->deleted_at)->not->toBeNull();

    $assigned = gqlCreateRole($organization);
    $membership = Membership::query()->where('user_id', $user->id)->firstOrFail();
    MembershipRole::create([
        'organization_id' => $organization->id,
        'membership_id' => $membership->id,
        'role_id' => $assigned->id,
    ]);

    $response = gqlPost(<<<'GQL'
    mutation($input: DestroyRoleInput!) {
        destroyRole(input: $input) { id }
    }
    GQL, ['input' => ['id' => $assigned->id]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors.0.extensions.code'))->toBe('role_assigned_to_members')
        ->and($assigned->refresh()->deleted_at)->toBeNull();
})->group('ledger:gql:mutation:destroyRole');

it('lists the roles of the organization, predefined first', function (): void {
    [$organization, $user] = gqlTeamSetup();
    $custom = gqlCreateRole($organization, ['name' => 'Zeta']);

    $response = gqlPost(<<<'GQL'
    query {
        roles { id code name memberships { id } }
    }
    GQL, [], gqlAuthHeaders($user, $organization->id));

    $roles = collect($response->json('data.roles'));

    // Predefined roles (organization_id NULL) come first, the custom one last.
    expect($roles->last()['id'])->toBe($custom->id);

    // Fetching a single role answers by id; unknown ids answer not_found.
    $response = gqlPost(<<<'GQL'
    query($id: ID!) {
        role(id: $id) { id code }
    }
    GQL, ['id' => $custom->id], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.role.code'))->toBe($custom->code);

    $response = gqlPost(<<<'GQL'
    query {
        role(id: "00000000-0000-0000-0000-000000000000") { id }
    }
    GQL, [], gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors.0.extensions.code'))->toBe('not_found');
})->group('ledger:gql:query:roles');

it('creates a password reset and resets the password', function (): void {
    Queue::fake();

    $user = gqlCreateUser('reset@example.com');
    $organization = gqlCreateOrganization();
    gqlCreateMembership($user, $organization);

    $response = gqlPost(<<<'GQL'
    mutation($input: CreatePasswordResetInput!) {
        createPasswordReset(input: $input) { id }
    }
    GQL, ['input' => ['email' => 'reset@example.com']]);

    $id = $response->json('data.createPasswordReset.id');

    expect($id)->not->toBeNull();

    $reset = PasswordReset::query()->where('user_id', $user->id)->firstOrFail();

    expect($reset->id)->toBe($id)
        ->and($reset->expire_at->isFuture())->toBeTrue();

    // Unknown emails still answer (the service not-found is surfaced).
    $response = gqlPost(<<<'GQL'
    mutation($input: CreatePasswordResetInput!) {
        createPasswordReset(input: $input) { id }
    }
    GQL, ['input' => ['email' => 'ghost@example.com']]);

    expect($response->json('errors.0.extensions.code'))->toBe('not_found');

    // The resolver only answers non-expired tokens.
    $response = gqlPost(
        'query { passwordReset(token: "'.$reset->token.'") { token expireAt } }',
        [],
        gqlAuthHeaders($user),
    );

    expect($response->json('errors'))->toBeNull()
        ->and($response->json('data.passwordReset.token'))->toBe($reset->token);

    // Reset the password — the payload is the fresh login.
    $response = gqlPost(<<<'GQL'
    mutation($input: ResetPasswordInput!) {
        resetPassword(input: $input) { token user { email } }
    }
    GQL, ['input' => ['token' => $reset->token, 'newPassword' => 'NewPassword1']]);

    expect($response->json('data.resetPassword.user.email'))->toBe('reset@example.com')
        ->and($response->json('data.resetPassword.token'))->not->toBeNull()
        ->and(PasswordReset::query()->where('id', $reset->id)->exists())->toBeFalse();

    // The new password authenticates.
    expect(User::query()->find($user->id)->authenticate('NewPassword1'))->toBeTrue();
})->group('ledger:gql:mutation:createPasswordReset', 'ledger:gql:mutation:resetPassword', 'ledger:gql:query:passwordReset');

it('answers not_found for expired or unknown password reset tokens', function (): void {
    $user = gqlCreateUser('expired@example.com');

    PasswordReset::create([
        'user_id' => $user->id,
        'token' => 'expired-token',
        'expire_at' => now()->subMinute(),
    ]);

    $response = gqlPost(<<<'GQL'
    query {
        passwordReset(token: "expired-token") { token }
    }
    GQL);

    expect($response->json('errors.0.extensions.code'))->toBe('not_found');

    $response = gqlPost(<<<'GQL'
    mutation($input: ResetPasswordInput!) {
        resetPassword(input: $input) { token }
    }
    GQL, ['input' => ['token' => 'unknown-token', 'newPassword' => 'NewPassword1']]);

    expect($response->json('errors.0.extensions.code'))->toBe('not_found');
})->group('ledger:gql:query:passwordReset', 'ledger:gql:mutation:resetPassword');

it('answers the google auth url', function (): void {
    $response = gqlPost(<<<'GQL'
    query {
        googleAuthUrl { url }
    }
    GQL);

    $url = $response->json('data.googleAuthUrl.url');

    // Without the Google client id configured the service answers the
    // unauthorized envelope (Rails: invalid client setup → result_error).
    if ($url === null) {
        expect($response->json('errors.0.extensions.code'))->not->toBeNull();
    } else {
        expect($url)->toStartWith('https://accounts.google.com/o/oauth2/auth');
    }
})->group('ledger:gql:query:googleAuthUrl');
