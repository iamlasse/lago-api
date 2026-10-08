<?php

declare(strict_types=1);

namespace App\Services\ClickHouse\Logs;

use App\Models\Organization;

/**
 * Port of Rails' Clickhouse::ApiLog model (app/models/clickhouse/api_log.rb)
 * + ApiLogsQuery (app/queries/api_logs_query.rb).
 *
 * Filters, in Rails' application order: retention (organization
 * audit_logs_period), logged_at from/to range, api_key_ids, request_ids,
 * http_statuses (with the succeeded/failed buckets), http_methods,
 * api_version, request_paths (exact or `*` wildcard, OR-joined), clients.
 */
class ApiLogQuery extends LogQuery
{
    /** Clickhouse::ApiLog::HTTP_METHODS — GraphQL enum names (get is not exposed). */
    public const HTTP_METHODS = [
        'get' => 1,
        'post' => 2,
        'put' => 3,
        'delete' => 4,
        'patch' => 5,
    ];

    public function table(): string
    {
        return 'api_logs';
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    protected function conditions(Organization $organization, array $filters): array
    {
        $conditions = ['organization_id = '.$this->quote($organization->id)];

        if (($retention = $this->retentionCondition($organization)) !== null) {
            $conditions[] = $retention;
        }

        $conditions = [...$conditions, ...$this->loggedAtRange($filters['from_date'] ?? null, $filters['to_date'] ?? null)];

        if (! empty($filters['api_key_ids'])) {
            $conditions[] = $this->inList('api_key_id', $filters['api_key_ids']);
        }

        if (! empty($filters['request_ids'])) {
            $conditions[] = $this->inList('request_id', $filters['request_ids']);
        }

        if (! empty($filters['http_statuses'])) {
            $conditions[] = $this->httpStatusCondition((array) $filters['http_statuses']);
        }

        if (! empty($filters['http_methods'])) {
            $conditions[] = $this->inList('http_method', $filters['http_methods']);
        }

        if (! empty($filters['api_version'])) {
            $conditions[] = $this->inList('api_version', [(string) $filters['api_version']]);
        }

        if (! empty($filters['request_paths'])) {
            $conditions[] = $this->requestPathsCondition((array) $filters['request_paths']);
        }

        if (! empty($filters['clients'])) {
            $conditions[] = $this->inList('client', $filters['clients']);
        }

        return $conditions;
    }

    /**
     * Rails `with_http_statuses` — a `succeeded`/`failed` bucket filter turns
     * into http_status ranges (<= 399 / > 399); anything else is an exact IN
     * match (invalid values are simply not found — the Rails TODO acknowledges
     * this).
     *
     * @param  list<mixed>  $statuses
     */
    private function httpStatusCondition(array $statuses): string
    {
        if (in_array('succeeded', $statuses, true) || in_array('failed', $statuses, true)) {
            $clauses = [];

            if (in_array('succeeded', $statuses, true)) {
                $clauses[] = 'http_status <= 399';
            }

            if (in_array('failed', $statuses, true)) {
                $clauses[] = 'http_status > 399';
            }

            return implode(' AND ', $clauses);
        }

        return $this->inList('http_status', $statuses);
    }

    /**
     * Rails `with_request_paths` — OR-joined exact / wildcard (`*` → `%`)
     * matches. Like the Rails version (path.tr("*", "%") through
     * sanitize_sql_array), LIKE metacharacters other than `*` are passed
     * through untouched.
     *
     * @param  list<string>  $paths
     */
    private function requestPathsCondition(array $paths): string
    {
        $clauses = [];

        foreach ($paths as $path) {
            if (str_contains((string) $path, '*')) {
                $clauses[] = 'request_path LIKE '.$this->quote(str_replace('*', '%', (string) $path));
            } else {
                $clauses[] = 'request_path = '.$this->quote($path);
            }
        }

        return '('.implode(' OR ', $clauses).')';
    }
}
