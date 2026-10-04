<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Membership;
use App\Models\Organization;

/**
 * Shared fixture builders for the GraphQL feature tests. Mirrors Rails'
 * factories: membership → active (status 0), user with "ILoveLago" password
 * (as in spec/graphql/mutations/login_user_spec.rb).
 */
function gqlCreateUser(string $email = 'login@example.com'): User
{
    return User::create(['email' => $email, 'password' => 'ILoveLago']);
}

function gqlCreateOrganization(string $name = 'Acme Corp'): Organization
{
    return Organization::create(['name' => $name]);
}

function gqlCreateMembership(User $user, Organization $organization, int $status = 0): Membership
{
    return Membership::create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'status' => $status,
    ]);
}

function gqlPost(string $query, array $variables = [], array $headers = []): Illuminate\Testing\TestResponse
{
    // Rails parity: the GraphQL engine answers at /graphql
    // (config/routes.rb: post '/graphql').
    return test()->postJson('/graphql', ['query' => $query, 'variables' => $variables], $headers);
}
