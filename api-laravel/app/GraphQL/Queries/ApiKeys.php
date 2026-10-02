<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\Page;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::ApiKeysResolver
 * (app/graphql/resolvers/api_keys_resolver.rb): "Query the API keys of
 * current organization" — created_at ASC, kaminari page/limit, returned as
 * the SanitizedApiKeyCollection shape (`collection` of masked keys +
 * `metadata`).
 */
class ApiKeys
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        [$page, $limit] = Page::normalizePageAndLimit($args['page'] ?? null, $args['limit'] ?? null);

        $paginator = $organization->apiKeys()->active()
            ->orderBy('created_at', 'asc')
            ->paginate(perPage: $limit, page: $page);

        return Page::fromLengthAwarePaginator($paginator);
    }
}
