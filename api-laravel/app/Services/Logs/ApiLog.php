<?php

declare(strict_types=1);

namespace App\Services\Logs;

use App\Models\Organization;
use App\Support\CurrentContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

/**
 * Port of Rails' Utils::ApiLog (app/services/utils/api_log.rb) — produces
 * an api log event to Kafka for ClickHouse consumption (the api_logs
 * queue → materialized view → api_logs table chain). One event per non-GET
 * public API request, produced after the response (see the ApiLoggable
 * middleware port).
 */
final class ApiLog
{
    /** Rails: Utils::ApiLog.available?. */
    public static function available(): bool
    {
        return (bool) config('lago.clickhouse.enabled')
            && Kafka::configured()
            && (bool) config('lago.kafka.api_logs_topic');
    }

    /**
     * Rails: `Utils::ApiLog.produce(request, response, organization:)`.
     */
    public static function produce(Request $request, object $response, Organization $organization): bool
    {
        if (! self::available()) {
            return false;
        }

        $requestId = self::requestId($request);
        $currentTime = Date::now()->utc()->format('Y-m-d\TH:i:s');

        return Kafka::produceAsync(
            topic: (string) config('lago.kafka.api_logs_topic'),
            payload: json_encode([
                'request_id' => $requestId,
                'organization_id' => $organization->id,
                'api_key_id' => CurrentContext::$apiKeyId,
                'api_version' => self::apiVersion($request),
                'client' => $request->userAgent(),
                'request_body' => (object) $request->except(['controller', 'action', 'format']),
                'request_path' => '/'.ltrim($request->path(), '/'),
                'request_origin' => $request->getSchemeAndHttpHost(),
                'http_method' => mb_strtolower($request->method()),
                'request_response' => self::responseBody($response),
                'http_status' => $response->status(),
                'logged_at' => $currentTime,
                'created_at' => $currentTime,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
        );
    }

    /** Rails: `request.request_id.presence || SecureRandom.uuid` (X-Request-Id). */
    private static function requestId(Request $request): string
    {
        return (string) ($request->header('X-Request-Id') ?: \Illuminate\Support\Str::uuid());
    }

    /** Rails: `request.path.match(/\/api\/(?<version>v\d+)\/.*/)[:version]`. */
    private static function apiVersion(Request $request): ?string
    {
        if (preg_match('#/api/(?<version>v\d+)/#', '/'.$request->path(), $matches) === 1) {
            return $matches['version'];
        }

        return null;
    }

    /** Rails: `response.body.present? ? JSON.parse(response.body) : nil`. */
    private static function responseBody(object $response): mixed
    {
        $body = (string) $response->getContent();

        if ($body === '') {
            return null;
        }

        $decoded = json_decode($body, true);

        return $decoded ?? $body;
    }
}
