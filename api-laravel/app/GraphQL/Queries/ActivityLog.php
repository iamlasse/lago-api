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
 * Port of Rails' Resolvers::ActivityLogResolver
 * (app/graphql/resolvers/activity_log_resolver.rb): "Query a single
 * activity log of an organization" by activity_id — premium-only, gated on
 * the activity-log infrastructure; RecordNotFound → not_found_error.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("audit_logs:view").
 */
class ActivityLog
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?array
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        if (! License::premium()) {
            throw new \App\GraphQL\Exceptions\ExecutionError('unauthorized', 'unauthorized', 'unauthorized');
        }

        if (! ActivityLog::available()) {
            throw Errors::forbiddenError('feature_unavailable');
        }

        $organization = LagoContext::currentOrganization($context);

        $log = (new ActivityLogQuery)->findBy($organization, 'activity_id', (string) $args['activityId']);

        if ($log === null) {
            throw Errors::notFoundError('activity_log');
        }

        return $log;
    }
}
