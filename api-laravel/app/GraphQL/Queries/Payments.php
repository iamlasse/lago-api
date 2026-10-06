<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\Args;
use App\GraphQL\Support\Page;
use App\Queries\PaymentsQuery;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::PaymentsResolver
 * (app/graphql/resolvers/payments_resolver.rb): "Query payments of an
 * organization" — invoice_id / external_customer_id / currency filters and
 * the search term through the `Payments\Query` port, wrapped in the frozen
 * SDL's PaymentCollection shape.
 */
class Payments
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $filters = Args::snakeKeys($args);
        unset($filters['page'], $filters['limit'], $filters['searchTerm']); // searchTerm: see the TODO below.

        // TODO(port): the searchTerm branches — PaymentsQuery's free-text
        // search (provider_payment_id / reference / invoice number / customer
        // fields) is still a TODO in the query port.
        $result = PaymentsQuery::call(
            organization: $organization,
            filters: $filters,
            pagination: [
                'page' => $args['page'] ?? null,
                'limit' => $args['limit'] ?? null,
            ],
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return Page::fromLengthAwarePaginator($result->payments);
    }
}
