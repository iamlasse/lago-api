<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Execution\Errors;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\GraphQL\Support\LagoContext;
use App\Services\ClickHouse\Logs\ApiLogQuery;
use App\Services\Logs\ApiLog;
use App\Support\License;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::ApiLogResolver
 * (app/graphql/resolvers/api_log_resolver.rb): "Query a single api log of
 * an organization" by request_id — premium-only, gated on the api-log
 * infrastructure; RecordNotFound → not_found_error.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("audit_logs:view").
 */
class ApiLog
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?array
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        if (! License::premium()) {
            throw new \App\GraphQL\Exceptions\ExecutionError('unauthorized', 'unauthorized', 'unauthorized');
        }

        if (! ApiLog::available()) {
            throw Errors::forbiddenError('feature_unavailable');
        }

        $organization = LagoContext::currentOrganization($context);

        $log = (new ApiLogQuery)->findBy($organization, 'request_id', (string) $args['requestId']);

        if ($log === null) {
            throw Errors::notFoundError('api_log');
        }

        return $log;
    }
}
