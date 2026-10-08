<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\ApiKey;
use Illuminate\Support\Facades\Date;

/**
 * Field resolvers for the frozen SDL's `ApiLog` type — port of Rails'
 * Types::ApiLogs::Object (app/graphql/types/api_logs/object.rb).
 *
 * The root is a raw ClickHouse row as returned by
 * App\Services\ClickHouse\Logs\ApiLogQuery.
 */
class ApiLog
{
    /** Rails: object.api_key (belongs_to api_key). */
    public function apiKey(array $root): ?ApiKey
    {
        $id = $root['api_key_id'] ?? null;

        return ($id !== null && $id !== '') ? ApiKey::find($id) : null;
    }

    public function apiVersion(array $root): ?string
    {
        return $this->stringOrNull($root['api_version'] ?? null);
    }

    public function client(array $root): ?string
    {
        return $this->stringOrNull($root['client'] ?? null);
    }

    public function createdAt(array $root): string
    {
        return $this->datetime($root['created_at']);
    }

    /** Stored Enum8 name — matches the SDL enum values verbatim (no `get`). */
    public function httpMethod(array $root): string
    {
        return (string) $root['http_method'];
    }

    /** FORMAT JSON returns UInt32 as a string — the SDL wants Int. */
    public function httpStatus(array $root): int
    {
        return (int) $root['http_status'];
    }

    /** Rails: deep_json_parser(object.request_body). */
    public function requestBody(array $root): ?array
    {
        return $this->deepJsonParser($root['request_body'] ?? null);
    }

    public function requestId(array $root): string
    {
        return (string) $root['request_id'];
    }

    public function requestOrigin(array $root): ?string
    {
        return $this->stringOrNull($root['request_origin'] ?? null);
    }

    public function requestPath(array $root): ?string
    {
        return $this->stringOrNull($root['request_path'] ?? null);
    }

    /** Rails: deep_json_parser(object.request_response) (non-null field). */
    public function requestResponse(array $root): array
    {
        return $this->deepJsonParser($root['request_response'] ?? null) ?? [];
    }

    public function loggedAt(array $root): string
    {
        return $this->datetime($root['logged_at']);
    }

    private function datetime(mixed $value): string
    {
        return Date::parse((string) $value)->utc()->toISOString();
    }

    private function stringOrNull(mixed $value): ?string
    {
        return ($value === null || $value === '') ? null : (string) $value;
    }

    /**
     * Rails' deep_json_parser — each map value is JSON.parse'd when it is a
     * string and kept when it decodes to an array/object (parse failures
     * keep the raw value).
     */
    private function deepJsonParser(mixed $map): ?array
    {
        if (! is_array($map)) {
            return $map === null ? null : (array) $map;
        }

        return array_map(static function (mixed $value): mixed {
            if (! is_string($value)) {
                return $value;
            }

            $parsed = json_decode($value, true);

            return is_array($parsed) ? $parsed : $value;
        }, $map);
    }
}
