<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\BillableMetrics\CreateService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::BillableMetrics::Create
 * (app/graphql/mutations/billable_metrics/create.rb): "Creates a new Billable
 * metric" — the whole input goes to BillableMetrics::CreateService merged
 * with the current organization id.
 */
class CreateBillableMetric
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        $result = CreateService::call(args: array_merge($input, [
            'organization_id' => $organization->id,
        ]));

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->billable_metric;
    }
}
