<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Execution\Errors;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\GraphQL\Support\LagoContext;
use App\Services\Logs\ApiLog;
use App\Support\License;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::ApiLogsResolver
 * (app/graphql/resolvers/api_logs_resolver.rb): "Query api logs of an
 * organization" — premium-only, gated on the api-log Kafka infrastructure,
 * through the ClickHouse ApiLogQuery port.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("audit_logs:view").
 */
class ApiLogs
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        if (! License::premium()) {
            throw new \App\GraphQL\Exceptions\ExecutionError('unauthorized', 'unauthorized', 'unauthorized');
        }

        // Rails: raise forbidden_error(code: "feature_unavailable") unless
        // Utils::ApiLog.available?
        if (! ApiLog::available()) {
            throw Errors::forbiddenError('feature_unavailable');
        }

        $organization = LagoContext::currentOrganization($context);

        return (new \App\Services\ClickHouse\Logs\ApiLogQuery)->page(
            organization: $organization,
            filters: self::filters($args),
            page: $args['page'] ?? null,
            limit: $args['limit'] ?? null,
        );
    }

    /**
     * Rails: from_date: args[:from_datetime] || args[:from_date], etc. The
     * Rails resolver also forwards `api_version: args[:api_version]`, which
     * is always nil (no such argument is declared) — kept nil here.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private static function filters(array $args): array
    {
        return [
            'from_date' => $args['fromDatetime'] ?? $args['fromDate'] ?? null,
            'to_date' => $args['toDatetime'] ?? $args['toDate'] ?? null,
            'api_key_ids' => $args['apiKeyIds'] ?? null,
            'request_ids' => $args['requestIds'] ?? null,
            'http_statuses' => $args['httpStatuses'] ?? null,
            'http_methods' => $args['httpMethods'] ?? null,
            'api_version' => null,
            'request_paths' => $args['requestPaths'] ?? null,
        ];
    }
}
