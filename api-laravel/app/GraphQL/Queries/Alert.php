<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use App\Models\UsageMonitoring\Alert as UsageMonitoringAlert;

/**
 * Port of Rails' Types::Query.alert — "Query a single subscription alert"
 * (frozen SDL: alert(id: ID!): Alert).
 */
class Alert
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?UsageMonitoringAlert
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $id = $args['id'] ?? null;

        return is_string($id)
            ? UsageMonitoringAlert::query()->where('organization_id', $organization->id)->find($id)
            : null;
    }
}
