<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Enums\InviteStatus;
use App\GraphQL\Execution\Errors;
use App\Models\Invite as InviteModel;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::InviteResolver
 * (app/graphql/resolvers/invite_resolver.rb): "Query a single Invite" — by
 * its pending token. Rails carries no AuthenticableApiUser gate here (this
 * is the pre-acceptance lookup the invitation screen makes before a JWT
 * exists), so the port answers unauthenticated requests too.
 */
class Invite
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?InviteModel
    {
        $invite = InviteModel::query()
            ->where('token', $args['token'] ?? null)
            ->where('status', InviteStatus::Pending->value)
            ->first();

        // Rails: not_found_error(resource: "invite") unless invite.
        if ($invite === null) {
            throw Errors::notFoundError('invite');
        }

        return $invite;
    }
}
