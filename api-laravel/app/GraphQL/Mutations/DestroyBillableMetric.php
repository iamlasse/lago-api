<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\Models\BillableMetric;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\BillableMetrics\DestroyService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::BillableMetrics::Destroy
 * (app/graphql/mutations/billable_metrics/destroy.rb): "Deletes a Billable
 * metric" — the payload's `id` is the destroyed metric's id.
 */
class DestroyBillableMetric
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.billable_metrics.find_by(id:).
        $billableMetric = BillableMetric::query()
            ->where('organization_id', $organization->id)
            ->find($input['id'] ?? null);

        $result = DestroyService::call(metric: $billableMetric);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->billable_metric;
    }
}
