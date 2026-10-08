<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Execution\Errors;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\GraphQL\Support\LagoContext;
use App\Services\ClickHouse\Logs\ActivityLogQuery;
use App\Services\Logs\ActivityLog;
use App\Support\License;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::ActivityLogsResolver
 * (app/graphql/resolvers/activity_logs_resolver.rb): "Query activity logs
 * of an organization" — premium-only, gated on the activity-log Kafka
 * infrastructure being configured, through the ClickHouse
 * ActivityLogQuery port.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("audit_logs:view").
 */
class ActivityLogs
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        // Rails: raise unauthorized_error unless License.premium?
        if (! License::premium()) {
            throw new \App\GraphQL\Exceptions\ExecutionError('unauthorized', 'unauthorized', 'unauthorized');
        }

        // Rails: raise forbidden_error(code: "feature_unavailable") unless
        // Utils::ActivityLog.available?
        if (! ActivityLog::available()) {
            throw Errors::forbiddenError('feature_unavailable');
        }

        $organization = LagoContext::currentOrganization($context);

        return (new ActivityLogQuery)->page(
            organization: $organization,
            filters: self::filters($args),
            page: $args['page'] ?? null,
            limit: $args['limit'] ?? null,
        );
    }

    /**
     * Rails: from_date: args[:from_datetime] || args[:from_date], etc.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private static function filters(array $args): array
    {
        $filters = [
            'from_date' => $args['fromDatetime'] ?? $args['fromDate'] ?? null,
            'to_date' => $args['toDatetime'] ?? $args['toDate'] ?? null,
            'api_key_ids' => $args['apiKeyIds'] ?? null,
            'activity_ids' => $args['activityIds'] ?? null,
            'activity_types' => $args['activityTypes'] ?? null,
            'activity_sources' => $args['activitySources'] ?? null,
            'user_emails' => $args['userEmails'] ?? null,
            'external_customer_id' => $args['externalCustomerId'] ?? null,
            'external_subscription_id' => $args['externalSubscriptionId'] ?? null,
            'resource_ids' => $args['resourceIds'] ?? null,
            'resource_types' => $args['resourceTypes'] ?? null,
        ];

        // activity_types / resource_types arrive as the GraphQL enum names;
        // they map onto the stored CH values here (graphql-ruby hands the
        // Rails query the enum values).
        $filters['activity_types'] = array_values(array_filter(array_map(
            ActivityLogQuery::activityTypeValue(...),
            array_values((array) $filters['activity_types']),
        )));

        $filters['resource_types'] = array_values(array_filter(array_map(
            ActivityLogQuery::resourceTypeValue(...),
            array_values((array) $filters['resource_types']),
        )));

        return $filters;
    }
}
