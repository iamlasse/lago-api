<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Models\QuoteVersion as QuoteVersionModel;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::QuoteVersionResolver#quote_version — the version
 * looked up in the current organization; an unknown id resolves null.
 */
class QuoteVersion
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?QuoteVersionModel
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        return QuoteVersionModel::query()
            ->where('organization_id', $organization->id)
            ->find($args['id'] ?? null);
    }
}
