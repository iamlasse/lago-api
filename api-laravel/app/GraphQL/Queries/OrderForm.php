<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\LagoContext;
use App\Models\OrderForm as OrderFormModel;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::OrderFormResolver#order_form — the order form
 * looked up in the current organization; an unknown id resolves null.
 */
class OrderForm
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?OrderFormModel
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        return OrderFormModel::query()
            ->where('organization_id', $organization->id)
            ->find($args['id'] ?? null);
    }
}
