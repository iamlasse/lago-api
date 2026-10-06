<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\Page;
use App\Queries\InvitesQuery;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::InvitesResolver
 * (app/graphql/resolvers/invites_resolver.rb): "Query pending invites of an
 * organization" — role ids filter, search term, kaminari pagination.
 *
 * TODO(port): the REQUIRED_PERMISSION gate.
 */
class Invites
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $result = InvitesQuery::call(
            organization: LagoContext::currentOrganization($context),
            searchTerm: $args['searchTerm'] ?? null,
            pagination: [
                'page' => $args['page'] ?? null,
                'limit' => $args['limit'] ?? null,
            ],
            filters: [
                'role_ids' => isset($args['roleIds']) ? array_values((array) $args['roleIds']) : null,
            ],
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return Page::fromLengthAwarePaginator($result->invites);
    }
}
