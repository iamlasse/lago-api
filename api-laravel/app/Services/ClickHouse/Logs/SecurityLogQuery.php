<?php

declare(strict_types=1);

namespace App\Services\ClickHouse\Logs;

use App\Models\Organization;

/**
 * Port of Rails' Clickhouse::SecurityLog model (app/models/clickhouse/
 * security_log.rb) + SecurityLogsQuery (app/queries/security_logs_query.rb).
 *
 * The model's default_scope (`logged_at >= SECURITY_LOGS_RETENTION_DAYS
 * .days.ago`, 90 days) applies to every read, on top of the from/to range
 * and the api_key_ids / user_ids / log_types / log_events filters. The
 * mandatory `to_date` boundary is validated at the resolvers (and in
 * SecurityLogsQuery itself: single_validation_failure value_is_mandatory).
 */
class SecurityLogQuery extends LogQuery
{
    /** Organization::SECURITY_LOGS_RETENTION_DAYS. */
    public const RETENTION_DAYS = 90;

    /** Clickhouse::SecurityLog::LOG_TYPES — names equal the stored values. */
    public const LOG_TYPES = [
        'api_key',
        'billing_entity',
        'export',
        'integration',
        'role',
        'user',
        'webhook_endpoint',
    ];

    /** Clickhouse::SecurityLog::LOG_EVENTS — names equal the stored values. */
    public const LOG_EVENTS = [
        'api_key.created',
        'api_key.deleted',
        'api_key.rotated',
        'api_key.updated',
        'billing_entity.created',
        'billing_entity.updated',
        'export.created',
        'integration.created',
        'integration.deleted',
        'integration.updated',
        'role.created',
        'role.deleted',
        'role.updated',
        'user.deleted',
        'user.new_device_logged_in',
        'user.invited',
        'user.password_edited',
        'user.password_reset_requested',
        'user.role_edited',
        'user.signed_up',
        'webhook_endpoint.created',
        'webhook_endpoint.deleted',
        'webhook_endpoint.updated',
    ];

    public function table(): string
    {
        return 'security_logs';
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    protected function conditions(Organization $organization, array $filters): array
    {
        // The model's default_scope: SecurityLog reads never look past the
        // 90-day retention window, regardless of the requested from_date.
        $conditions = [
            'organization_id = '.$this->quote($organization->id),
            'logged_at >= '.$this->quotedDatetime(now()->subDays(self::RETENTION_DAYS)),
        ];

        $conditions = [...$conditions, ...$this->loggedAtRange($filters['from_date'] ?? null, $filters['to_date'] ?? null)];

        if (! empty($filters['api_key_ids'])) {
            $conditions[] = $this->inList('api_key_id', $filters['api_key_ids']);
        }

        if (! empty($filters['user_ids'])) {
            $conditions[] = $this->inList('user_id', $filters['user_ids']);
        }

        if (! empty($filters['log_types'])) {
            $conditions[] = $this->inList('log_type', $filters['log_types']);
        }

        if (! empty($filters['log_events'])) {
            $conditions[] = $this->inList('log_event', $filters['log_events']);
        }

        return $conditions;
    }
}
