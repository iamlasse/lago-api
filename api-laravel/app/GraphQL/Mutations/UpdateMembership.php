<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Membership;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\Memberships\UpdateService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Memberships::Update
 * (app/graphql/mutations/memberships/update.rb): "Update a membership" —
 * the roles sync of the membership_roles pivot.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("organization:members:update").
 */
class UpdateMembership
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $input = is_array($args['input'] ?? null) ? $args['input'] : $args;

        // Rails: current_organization.memberships.find_by(id:); the whole
        // args hash is forwarded as params (the service reads params[:roles]).
        $membership = Membership::query()
            ->where('organization_id', LagoContext::currentOrganization($context)->id)
            ->where('id', $input['id'] ?? null)
            ->first();

        $result = UpdateService::call(
            user: LagoContext::currentUser($context),
            membership: $membership,
            params: ['roles' => $input['roles'] ?? null],
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->membership;
    }
}
