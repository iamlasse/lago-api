<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use Illuminate\Support\Facades\Date;

/**
 * Field resolvers for the frozen SDL's `SecurityLog` type — port of Rails'
 * Types::SecurityLogs::Object (app/graphql/types/security_logs/object.rb).
 *
 * The root is a raw ClickHouse row as returned by
 * App\Services\ClickHouse\Logs\SecurityLogQuery.
 */
class SecurityLog
{
    public function createdAt(array $root): string
    {
        return $this->datetime($root['created_at']);
    }

    /** Rails: Clickhouse::SecurityLog#device_info (deep_parse_map_values). */
    public function deviceInfo(array $root): ?array
    {
        return $this->deepParseMapValues($root['device_info'] ?? null);
    }

    /** Stored value — the SDL enum names are the values with "." → "_". */
    public function logEvent(array $root): string
    {
        return str_replace('.', '_', (string) $root['log_event']);
    }

    public function logId(array $root): string
    {
        return (string) $root['log_id'];
    }

    public function logType(array $root): string
    {
        return (string) $root['log_type'];
    }

    public function loggedAt(array $root): string
    {
        return $this->datetime($root['logged_at']);
    }

    /** Rails: Clickhouse::SecurityLog#resources (deep_parse_map_values). */
    public function resources(array $root): ?array
    {
        return $this->deepParseMapValues($root['resources'] ?? null);
    }

    /** Rails: object.user&.email. */
    public function userEmail(array $root): ?string
    {
        $id = $root['user_id'] ?? null;

        if ($id === null || $id === '') {
            return null;
        }

        return \App\Models\User::find($id)?->email;
    }

    private function datetime(mixed $value): string
    {
        return Date::parse((string) $value)->utc()->toISOString();
    }

    /**
     * Rails' deep_parse_map_values — JSON.parse each map value, keeping the
     * raw string when it does not parse.
     */
    private function deepParseMapValues(mixed $map): ?array
    {
        if (! is_array($map)) {
            return $map === null ? null : (array) $map;
        }

        return array_map(static function (mixed $value): mixed {
            if (! is_string($value)) {
                return $value;
            }

            $parsed = json_decode($value, true);

            return $parsed ?? $value;
        }, $map);
    }
}
