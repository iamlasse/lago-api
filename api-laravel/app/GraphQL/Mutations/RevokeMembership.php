<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Membership;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\Memberships\RevokeService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Memberships::Revoke
 * (app/graphql/mutations/memberships/revoke.rb): "Revoke a membership".
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("organization:members:update").
 */
class RevokeMembership
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $input = is_array($args['input'] ?? null) ? $args['input'] : $args;

        // Rails: current_organization.memberships.find_by(id:).
        $membership = Membership::query()
            ->where('organization_id', LagoContext::currentOrganization($context)->id)
            ->where('id', $input['id'] ?? null)
            ->first();

        $result = RevokeService::call(
            user: LagoContext::currentUser($context),
            membership: $membership,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->membership;
    }
}
