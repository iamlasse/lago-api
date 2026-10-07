<?php

declare(strict_types=1);

namespace App\Http\Client;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;

/**
 * Port of LagoHttpClient::SessionClient
 * (lib/lago_http_client/lago_http_client/session_client.rb), built on
 * Laravel's HTTP client so tests can stub delivery with Http::fake().
 *
 * A cookie-carrying client for one base URL: every request injects the
 * cookies collected from earlier responses as a `Cookie` header, and every
 * response's `Set-Cookie` values are merged in (name-keyed, replacing).
 * Used by the Superset integration, whose login/CSRF flow depends on the
 * session cookie surviving between calls.
 *
 * Rails differences ported verbatim:
 *  - response codes outside RESPONSE_SUCCESS_CODES raise LagoHttpError,
 *    EXCEPT redirects, which are returned as-is (the client never follows
 *    them — `withoutRedirecting()` below);
 *  - connection-level failures (SSL/open/read timeout — ConnectionException
 *    here) are retried up to MAX_RETRIES_ATTEMPTS times, then rethrown.
 */
class LagoSessionClient
{
    /** Rails: RESPONSE_SUCCESS_CODES. */
    public const array RESPONSE_SUCCESS_CODES = [200, 201, 202, 204];

    /** Rails: MAX_RETRIES_ATTEMPTS. */
    public const int MAX_RETRIES_ATTEMPTS = 3;

    /** @var list<string> "name=value" pairs, in arrival order. */
    protected array $cookies = [];

    public function __construct(
        public readonly string $baseUrl,
        public readonly int $readTimeout = 30,
        public readonly int $openTimeout = 30,
    ) {}

    /**
     * Rails: `get(path, headers:)` — GETs base_url + path with the given
     * headers and the session cookies; returns the raw response (callers
     * JSON-parse `body` themselves).
     *
     * @param  array<string, string>  $headers
     */
    public function get(string $path, array $headers = []): Response
    {
        return $this->executeRequest(function () use ($path, $headers): Response {
            $pending = $this->pendingRequest($headers);

            return $pending->get($this->uri($path));
        });
    }

    /**
     * Rails: `post(path, body:, headers:)` — POSTs the body encoded per the
     * Content-Type header (JSON by default) with the given headers and the
     * session cookies.
     *
     * @param  array<string, mixed>|string  $body
     * @param  array<string, string>  $headers
     */
    public function post(string $path, array|string $body = [], array $headers = []): Response
    {
        return $this->executeRequest(function () use ($path, $body, $headers): Response {
            $pending = $this->pendingRequest($headers);

            return $pending->withBody($this->formatBody($body, $headers), $this->bodyContentType($headers))
                ->post($this->uri($path));
        });
    }

    /** Rails: `clear_cookies`. */
    public function clearCookies(): void
    {
        $this->cookies = [];
    }

    /** @return list<string> */
    public function cookies(): array
    {
        return $this->cookies;
    }

    /** @param  array<string, string>  $headers */
    private function pendingRequest(array $headers): \Illuminate\Http\Client\PendingRequest
    {
        $pending = Http::withHeaders($headers)
            ->withoutRedirecting()
            ->when($this->readTimeout > 0, fn ($http) => $http->timeout($this->readTimeout))
            ->when($this->openTimeout > 0, fn ($http) => $http->connectTimeout($this->openTimeout));

        if ($this->cookies !== []) {
            $pending = $pending->withHeaders(['Cookie' => implode('; ', $this->cookies)]);
        }

        return $pending;
    }

    /**
     * Rails: `execute_request` — stores cookies off the response, validates
     * the status, retries connection-level failures up to 3 times.
     *
     * @param  callable(): Response  $request
     */
    private function executeRequest(callable $request): Response
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = $request();

                $this->storeCookies($response);
                $this->validateResponse($response);

                return $response;
            } catch (ConnectionException $connectionException) {
                if ($attempt >= self::MAX_RETRIES_ATTEMPTS) {
                    throw $connectionException;
                }
            }
        }
    }

    /** @param  array<string, mixed>|string  $body */
    private function formatBody(array|string $body, array $headers): string
    {
        if (is_string($body)) {
            return $body;
        }

        return json_encode($body, JSON_UNESCAPED_SLASHES);
    }

    /** @param  array<string, string>  $headers */
    private function bodyContentType(array $headers): string
    {
        return $headers['Content-Type'] ?? $headers['content-type'] ?? 'application/json';
    }

    private function storeCookies(Response $response): void
    {
        $setCookies = $response->headers()['Set-Cookie'] ?? [];

        foreach ($setCookies as $cookie) {
            // Rails: keep only the "name=value" pair, drop attributes.
            $cookieValue = explode(';', $cookie)[0];
            $cookieName = explode('=', $cookieValue)[0];

            $this->cookies = array_values(array_filter(
                $this->cookies,
                fn (string $existing) => ! str_starts_with($existing, $cookieName.'='),
            ));

            $this->cookies[] = $cookieValue;
        }
    }

    private function validateResponse(Response $response): void
    {
        $code = $response->status();

        if (in_array($code, self::RESPONSE_SUCCESS_CODES, true)) {
            return;
        }

        // Rails: redirects are returned to the caller, never followed.
        if ($code >= 300 && $code < 400) {
            return;
        }

        throw new LagoHttpError($code, $response->body(), $this->uri(''), $response->headers());
    }

    /** Rails: URI.join(base_url, path). */
    private function uri(string $path): string
    {
        if ($path === '') {
            return $this->baseUrl;
        }

        return mb_rtrim($this->baseUrl, '/').'/'.mb_ltrim($path, '/');
    }
}
