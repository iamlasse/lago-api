<?php
declare(strict_types=1);
require_once __DIR__.'/GraphQLHelpers.php';
require_once __DIR__.'/AuthPlumbingTest.php';
use App\Models\Role;
use App\Models\MembershipRole;
it('debug invite', function (): void {
    $organization = gqlCreateOrganization();
    $user = gqlCreateUser('team-admin2@example.com');
    $membership = gqlCreateMembership($user, $organization);
    $admin = Role::query()->where('admin', true)->first();
    if ($admin === null) {
        $admin = Role::create(['organization_id' => null, 'code' => 'admin', 'name' => 'Admin', 'admin' => true, 'permissions' => []]);
    }
    MembershipRole::create(['organization_id' => $organization->id, 'membership_id' => $membership->id, 'role_id' => $admin->id]);
    $m = $membership->refresh();
    dump('admin?', $m->roles()->where('roles.admin', true)->exists(), $m->roles()->toSql());
    $response = gqlPost('mutation($input: CreateInviteInput!) { createInvite(input: $input) { id email } }',
        ['input' => ['email' => 'dbg3@example.com', 'roles' => ['some_custom_role_only']]]);
    dump($response->json());
    expect(true)->toBeTrue();
});
