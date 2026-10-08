<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Execution\Errors;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\GraphQL\Support\LagoContext;
use App\Services\ClickHouse\Logs\SecurityLogQuery;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::SecurityLogResolver
 * (app/graphql/resolvers/security_log_resolver.rb): "Query a single
 * security log by ID" — gated on ClickHouse being configured and the
 * organization's security_logs premium integration; RecordNotFound →
 * not_found_error.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("security_logs:view").
 */
class SecurityLog
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?array
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        // Rails: raise forbidden_error(code: "feature_unavailable") unless
        // SecurityLogsQuery.available? / organization.security_logs_enabled?
        if (! SecurityLogs::available()) {
            throw Errors::forbiddenError('feature_unavailable');
        }

        if (! $organization->securityLogsEnabled()) {
            throw Errors::forbiddenError('feature_unavailable');
        }

        $log = (new SecurityLogQuery)->findBy($organization, 'log_id', (string) $args['logId']);

        if ($log === null) {
            throw Errors::notFoundError('security_log');
        }

        return $log;
    }
}
