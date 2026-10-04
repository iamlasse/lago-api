<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Queries\AddOnsQuery;
use App\GraphQL\Support\Page;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::AddOnsResolver
 * (app/graphql/resolvers/add_ons_resolver.rb): "Query add-ons of an
 * organization" — the free-text search term goes through the AddOnsQuery
 * port, wrapped in the frozen SDL's AddOnCollection shape (`collection` +
 * `metadata`).
 */
class AddOns
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $result = AddOnsQuery::call(
            organization: $organization,
            pagination: [
                'page' => $args['page'] ?? null,
                'limit' => $args['limit'] ?? null,
            ],
            searchTerm: $args['searchTerm'] ?? null,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return Page::fromLengthAwarePaginator($result->add_ons);
    }
}
