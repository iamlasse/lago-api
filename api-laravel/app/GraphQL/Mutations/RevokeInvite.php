<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Invite;
use App\Enums\InviteStatus;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\Invites\RevokeService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Invites::Revoke
 * (app/graphql/mutations/invites/revoke.rb): "Revokes an invite" — only
 * pending invites are reachable.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("organization:members:delete").
 */
class RevokeInvite
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $input = is_array($args['input'] ?? null) ? $args['input'] : $args;

        // Rails: current_organization.invites.pending.find_by(id:, status:
        // :pending) — an unknown/accepted id yields a nil invite, which the
        // service turns into not_found_failure("invite").
        $invite = Invite::query()
            ->where('organization_id', LagoContext::currentOrganization($context)->id)
            ->where('status', InviteStatus::Pending->value)
            ->where('id', $input['id'] ?? null)
            ->first();

        $result = RevokeService::call(invite: $invite);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->invite;
    }
}
