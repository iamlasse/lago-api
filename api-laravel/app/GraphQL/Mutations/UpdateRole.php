<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Role;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\Roles\UpdateService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Roles::Update
 * (app/graphql/mutations/roles/update.rb): "Updates an existing custom role".
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("roles:update").
 */
class UpdateRole
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $input = is_array($args['input'] ?? null) ? $args['input'] : $args;

        $organization = LagoContext::currentOrganization($context);

        // Rails: Role.with_organization(current_organization.id).find_by(id:).
        $role = Role::query()
            ->where(function ($query) use ($organization): void {
                $query->whereNull('organization_id')
                    ->orWhere('organization_id', $organization->id);
            })
            ->where('id', $input['id'] ?? null)
            ->first();

        $result = UpdateService::call(
            role: $role,
            params: [
                'name' => $input['name'] ?? null,
                'description' => $input['description'] ?? null,
                'permissions' => $input['permissions'] ?? null,
            ],
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->role;
    }
}
