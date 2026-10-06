<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Models\BillableMetric as BillableMetricModel;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::BillableMetricResolver
 * (app/graphql/resolvers/billable_metric_resolver.rb): "Query a single
 * billable metric of an organization" — an unknown id answers the not_found
 * envelope.
 */
class BillableMetric
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?BillableMetricModel
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        // Rails: current_organization.billable_metrics.find(id) — a discarded
        // metric no longer resolves (the default kept scope).
        $found = BillableMetricModel::query()
            ->where('organization_id', $organization->id)
            ->find($args['id'] ?? null);

        if ($found === null) {
            throw Errors::notFoundError('billable_metric');
        }

        return $found;
    }
}
