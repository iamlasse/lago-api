<?php

declare(strict_types=1);

namespace App\Services\Logs;

use App\Models\Membership;
use App\Support\CurrentContext;

/**
 * Port of Rails' Utils::SecurityLog (app/services/utils/security_log.rb) —
 * produces security log events to Kafka for ClickHouse consumption.
 *
 * Security logs track user and system configuration changes: user
 * management, role changes, API key rotations, webhook configuration.
 *
 * Unlike Activity Logs, Security Logs:
 * - Do not track customer/subscription data
 * - Use a flat resources map instead of a polymorphic resource
 * - Require a per-org premium integration (not just global
 *   App\Support\License::premium())
 * - Are collected ONLY for cloud Premium organizations
 */
final class SecurityLog
{
    /** Rails: Utils::SecurityLog.available?. */
    public static function available(): bool
    {
        return (bool) config('lago.clickhouse.enabled')
            && Kafka::configured()
            && (bool) config('lago.kafka.security_logs_topic');
    }

    /** Rails: `Utils::SecurityLog.topic` (ENV["LAGO_KAFKA_SECURITY_LOGS_TOPIC"]). */
    public static function topic(): string
    {
        return (string) config('lago.kafka.security_logs_topic');
    }

    /**
     * Rails: `Utils::SecurityLog.produce(organization:, log_type:, log_event:,
     * user:, api_key:, resources:, device_info:, skip_organization_check:)`.
     *
     * @param  object|null  $user  the acting user (nil for API key operations)
     * @param  object|null  $apiKey  the API key used for the action
     * @param  array<string, mixed>|null  $resources  additional context (e.g. {invitee_email: "..."})
     * @param  array<string, mixed>|null  $deviceInfo  device metadata for login events
     */
    public static function produce(
        object $organization,
        string $logType,
        string $logEvent,
        ?object $user = null,
        ?object $apiKey = null,
        ?array $resources = null,
        ?array $deviceInfo = null,
        bool $skipOrganizationCheck = false,
    ): bool {
        if (! self::available()) {
            return false;
        }

        $securityLogsEnabled = $organization->securityLogsEnabled();

        if (! $skipOrganizationCheck && ! $securityLogsEnabled) {
            return false;
        }

        $logId = (string) \Illuminate\Support\Str::uuid();
        $currentTime = now()->utc()->format('Y-m-d\TH:i:s');

        return Kafka::produceAsync(
            topic: self::topic(),
            payload: json_encode([
                'organization_id' => $organization->id,
                'user_id' => self::resolveUserId($organization, $user),
                'api_key_id' => $apiKey?->id,
                'log_id' => $logId,
                'log_type' => $logType,
                'log_event' => $logEvent,
                'device_info' => (object) ($deviceInfo ?? CurrentContext::$deviceInfo ?? []),
                'resources' => (object) ($resources ?? []),
                'logged_at' => $currentTime,
                'created_at' => $currentTime,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
        );
    }

    /**
     * Rails: the given user wins; otherwise nil with an api key in context;
     * otherwise the context membership's user.
     */
    private static function resolveUserId(object $organization, ?object $user): ?string
    {
        if ($user !== null) {
            return (string) $user->id;
        }

        if (CurrentContext::$apiKeyId !== null && CurrentContext::$apiKeyId !== '') {
            return null;
        }

        $membership = CurrentContext::$membership;

        if ($membership === null) {
            return null;
        }

        if ($membership instanceof Membership) {
            return Membership::query()
                ->where('organization_id', $organization->id)
                ->where('id', $membership->id)
                ->value('user_id');
        }

        return null;
    }
}
