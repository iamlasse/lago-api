<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Models\Payment as PaymentModel;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::PaymentResolver
 * (app/graphql/resolvers/payment_resolver.rb): "Query a single Payment" —
 * Payment.for_organization(current_organization).find(id) (the visible-
 * payable rule), unknown ids answer the not_found envelope.
 */
class Payment
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?PaymentModel
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        /** @var \App\Models\Organization $organization */
        $organization = LagoContext::currentOrganization($context);

        $found = PaymentModel::forOrganization($organization)
            ->where('payments.id', $args['id'] ?? null)
            ->first();

        if ($found === null) {
            throw Errors::notFoundError('payment');
        }

        return $found;
    }
}
