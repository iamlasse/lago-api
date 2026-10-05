<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Quote as QuoteModel;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::QuoteResolver#quote — the quote looked up in the
 * current organization; an unknown id resolves null (the field is nullable).
 */
class Quote
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?QuoteModel
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        return QuoteModel::query()
            ->where('organization_id', $organization->id)
            ->find($args['id'] ?? null);
    }
}
