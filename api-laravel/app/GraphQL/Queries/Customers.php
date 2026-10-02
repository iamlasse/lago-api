<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\Args;
use App\GraphQL\Support\Page;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\Customers\Query as CustomersQuery;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::CustomersResolver
 * (app/graphql/resolvers/customers_resolver.rb): "Query customers of an
 * organization" — filters, search term, kaminari pagination, wrapped in the
 * frozen SDL's CustomerCollection shape (`collection` + `metadata`).
 *
 * Rails folds the [CustomerMetadataFilter!] list into a key→value hash
 * (`metadata.to_h { |m| [m[:key], m[:value]] }`) before calling the query.
 */
class Customers
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $filters = Args::snakeKeys($args);
        unset($filters['page'], $filters['limit'], $filters['searchTerm']);

        $metadata = $args['metadata'] ?? null;
        if ($metadata !== null) {
            // Rails: metadata.to_h { |m| [m[:key], m[:value]] }
            $filters['metadata'] = collect($metadata)
                ->mapWithKeys(fn ($entry): array => [$entry['key'] => $entry['value'] ?? null])
                ->all();
        }

        $result = CustomersQuery::call(
            organization: $organization,
            filters: $filters,
            pagination: [
                'page' => $args['page'] ?? null,
                'limit' => $args['limit'] ?? null,
            ],
            searchTerm: $args['searchTerm'] ?? null,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return Page::fromLengthAwarePaginator($result->customers);
    }
}
