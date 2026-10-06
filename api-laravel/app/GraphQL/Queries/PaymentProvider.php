<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Models\PaymentProvider as PaymentProviderModel;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::PaymentProviderResolver
 * (app/graphql/resolvers/payment_provider_resolver.rb): "Query a single
 * payment provider" — by id, otherwise by code (`find_by!(code:)` answers
 * the not_found envelope on a miss, both shapes).
 */
class PaymentProvider
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): PaymentProviderModel
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $id = $args['id'] ?? null;
        $code = $args['code'] ?? null;

        $scope = PaymentProviderModel::query()->where('organization_id', $organization->id);

        $found = $id !== null && $id !== ''
            ? $scope->find($id)
            : $scope->where('code', $code)->first();

        if ($found === null) {
            throw Errors::notFoundError('payment_provider');
        }

        return $found;
    }
}
