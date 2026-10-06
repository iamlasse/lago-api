<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\Args;
use App\GraphQL\Support\Page;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Queries\PaymentMethodsQuery;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::PaymentMethodsResolver
 * (app/graphql/resolvers/payment_methods_resolver.rb): "Query payment
 * methods of a customer" — external_customer_id + with_deleted through the
 * `PaymentMethods\Query` port, eager-loading the provider like Rails'
 * `.includes(:payment_provider)`.
 */
class PaymentMethods
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $filters = Args::snakeKeys($args);
        unset($filters['page'], $filters['limit']);

        $result = PaymentMethodsQuery::call(
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

        return Page::fromLengthAwarePaginator($result->payment_methods);
    }
}
