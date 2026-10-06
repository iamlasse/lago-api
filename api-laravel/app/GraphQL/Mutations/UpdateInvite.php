<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Invite;
use App\Enums\InviteStatus;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\Invites\UpdateService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Invites::Update
 * (app/graphql/mutations/invites/update.rb): "Update an invite" — the roles
 * of a pending invite.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("organization:members:update").
 */
class UpdateInvite
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $input = is_array($args['input'] ?? null) ? $args['input'] : $args;

        // Rails: current_organization.invites.pending.find_by(id: args[:id]).
        $invite = Invite::query()
            ->where('organization_id', LagoContext::currentOrganization($context)->id)
            ->where('status', InviteStatus::Pending->value)
            ->where('id', $input['id'] ?? null)
            ->first();

        $result = UpdateService::call(
            user: LagoContext::currentUser($context),
            invite: $invite,
            params: ['roles' => $input['roles'] ?? null],
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->invite;
    }
}
