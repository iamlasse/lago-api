<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\Roles\CreateService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Roles::Create
 * (app/graphql/mutations/roles/create.rb): "Creates a new custom role".
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("roles:create").
 */
class CreateRole
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $input = is_array($args['input'] ?? null) ? $args['input'] : $args;

        $result = CreateService::call(
            organization: LagoContext::currentOrganization($context),
            code: $input['code'] ?? null,
            name: $input['name'] ?? null,
            description: $input['description'] ?? null,
            permissions: isset($input['permissions']) ? array_values((array) $input['permissions']) : null,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->role;
    }
}
