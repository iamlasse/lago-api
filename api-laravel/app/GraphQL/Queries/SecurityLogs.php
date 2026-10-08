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
 * Port of Rails' Resolvers::SecurityLogsResolver
 * (app/graphql/resolvers/security_logs_resolver.rb): "Query security logs
 * of an organization" — gated on ClickHouse being configured
 * (SecurityLogsQuery.available?) and the organization's security_logs
 * premium integration; to_datetime is mandatory ("value_is_mandatory"),
 * through the ClickHouse SecurityLogQuery port.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("security_logs:view").
 */
class SecurityLogs
{
    /** Rails: SecurityLogsQuery.available? — ENV["LAGO_CLICKHOUSE_ENABLED"]. */
    public static function available(): bool
    {
        return (bool) config('lago.clickhouse.enabled');
    }

    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        // Rails (SecurityLogsQuery#call): forbidden unless available?, unless
        // organization.security_logs_enabled?, and to_date is mandatory.
        if (! self::available()) {
            throw Errors::forbiddenError('feature_unavailable');
        }

        if (! $organization->securityLogsEnabled()) {
            throw Errors::forbiddenError('feature_unavailable');
        }

        if (empty($args['toDatetime'])) {
            throw Errors::validationError(['to_date' => ['value_is_mandatory']]);
        }

        return (new SecurityLogQuery)->page(
            organization: $organization,
            filters: self::filters($args),
            page: $args['page'] ?? null,
            limit: $args['limit'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private static function filters(array $args): array
    {
        return [
            'from_date' => $args['fromDatetime'] ?? null,
            'to_date' => $args['toDatetime'] ?? null,
            'api_key_ids' => $args['apiKeyIds'] ?? null,
            'user_ids' => $args['userIds'] ?? null,
            'log_types' => $args['logTypes'] ?? null,
            'log_events' => $args['logEvents'] ?? null,
        ];
    }
}
