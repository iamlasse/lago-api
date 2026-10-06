<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\Invites\CreateService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Invites::Create
 * (app/graphql/mutations/invites/create.rb): "Creates a new Invite".
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("organization:members:create").
 */
class CreateInvite
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $input = is_array($args['input'] ?? null) ? $args['input'] : $args;

        $result = CreateService::call(
            current_organization: LagoContext::currentOrganization($context),
            user: LagoContext::currentUser($context),
            email: $input['email'] ?? null,
            roles: isset($input['roles']) ? array_values((array) $input['roles']) : null,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->invite;
    }
}
