<?php

declare(strict_types=1);

namespace App\Http\Client;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;

/**
 * Port of LagoHttpClient::Client
 * (lib/lago_http_client/lago_http_client/client.rb), built on Laravel's HTTP
 * client so tests can stub delivery with Http::fake().
 *
 * Only the surface the webhook pipeline and the Data API proxy use is ported
 * (post_with_response, get + the retry_on_transient_errors mode); the gem's
 * multipart / SSE-stream / session-cookie methods are out of scope until
 * their consumers are ported.
 *
 * TODO(port): Rails pins the resolved address on the underlying socket
 * (build_http_client(ipaddr)) to defeat DNS rebinding between the
 * AddressGuard check and the connection; Laravel's HTTP client offers no
 * per-request connect-to override, so the guard validates all resolved
 * addresses but the final DNS lookup happens at connect time.
 */
class LagoHttpClient
{
    /** Rails: RESPONSE_SUCCESS_CODES. */
    public const array RESPONSE_SUCCESS_CODES = [200, 201, 202, 204];

    /** Rails: RETRYABLE_HTTP_STATUSES (only retried when opted in). */
    public const array RETRYABLE_HTTP_STATUSES = [500, 502, 503, 504];

    /** Rails: MAX_RETRIES_ATTEMPTS. */
    public const int MAX_RETRIES_ATTEMPTS = 3;

    public function __construct(
        public readonly string $url,
        public readonly ?int $openTimeout = null,
        public readonly ?int $readTimeout = null,
        public readonly ?int $writeTimeout = null,
        public readonly bool $blockPrivateAddresses = false,
        public readonly bool $retryOnTransientErrors = false,
    ) {}

    /**
     * Rails: `get` — GETs the endpoint (query-encoded params, custom headers)
     * and JSON-parses the body (`response.body.presence || "{}"`, so an empty
     * body is an empty array; a malformed body raises, like the gem's
     * JSON::ParserError). Raises LagoHttpError for non-success codes.
     *
     * When `retry_on_transient_errors` is set (the Data API base service opts
     * in), connection errors and transient statuses (500/502/503/504) are
     * retried up to MAX_RETRIES_ATTEMPTS with a 0.25-0.5s backoff, exactly
     * like the gem's `request` loop.
     *
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>|null  $params
     */
    public function get(array $headers = [], ?array $params = null): mixed
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $this->getOnce($headers, $params);
            } catch (LagoHttpError|ConnectionException $error) {
                if ($attempt >= self::MAX_RETRIES_ATTEMPTS || ! $this->retryable($error)) {
                    throw $error;
                }

                // Rails: RETRY_BACKOFF_RANGE = (0.25..0.5) seconds.
                \Illuminate\Support\Sleep::usleep(random_int(250_000, 500_000));
            }
        }
    }

    /**
     * Rails: `post_with_response` — POSTs the JSON-encoded body with the
     * given headers and raises LagoHttpError for non-success codes.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    public function postWithResponse(array $body, array $headers): Response
    {
        return $this->request('POST', json_encode($body, JSON_UNESCAPED_SLASHES), $headers);
    }

    /**
     * Rails: `put_with_response` — same contract as post_with_response over
     * PUT. The aggregator contacts update calls go through this.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    public function putWithResponse(array $body, array $headers): Response
    {
        return $this->request('PUT', json_encode($body, JSON_UNESCAPED_SLASHES), $headers);
    }

    /** Rails: `post_with_response` with an already-encoded body. */
    public function postRawWithResponse(string $encodedBody, array $headers): Response
    {
        return $this->request('POST', $encodedBody, $headers);
    }

    /**
     * Rails: `post_url_encoded(params, headers)` — POSTs the params as
     * application/x-www-form-urlencoded and JSON-parses the body
     * (`response.body.presence || "{}"`); raises LagoHttpError for
     * non-success codes. The Okta/Entra SSO token exchanges go through this
     * (app/services/auth/okta/base_service.rb, entra_id/base_service.rb).
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, string>  $headers
     */
    public function postUrlEncoded(array $params, array $headers = []): mixed
    {
        $this->guardAddress();

        $response = Http::withHeaders($headers)
            ->asForm()
            ->when($this->readTimeout !== null, fn ($http) => $http->timeout($this->readTimeout))
            ->when($this->openTimeout !== null, fn ($http) => $http->connectTimeout($this->openTimeout))
            ->post($this->url, $params);

        $code = $response->status();
        if (! in_array($code, self::RESPONSE_SUCCESS_CODES, true)) {
            throw new LagoHttpError($code, $response->body(), $this->url, $response->headers());
        }

        // Rails: JSON.parse(response.body.presence || "{}").
        $body = $response->body();

        return json_decode($body === '' ? '{}' : $body, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param  array<string, string>  $headers
     *  @param  array<string, mixed>|null  $params */
    protected function getOnce(array $headers, ?array $params): mixed
    {
        $this->guardAddress();

        $url = $params !== null && $params !== []
            ? $this->url.'?'.http_build_query($params)
            : $this->url;

        $pending = Http::withHeaders($headers)
            ->when($this->readTimeout !== null, fn ($http) => $http->timeout($this->readTimeout))
            ->when($this->openTimeout !== null, fn ($http) => $http->connectTimeout($this->openTimeout));

        $response = $pending->get($url);

        $code = $response->status();
        if (! in_array($code, self::RESPONSE_SUCCESS_CODES, true)) {
            throw new LagoHttpError($code, $response->body(), $this->url, $response->headers());
        }

        // Rails: JSON.parse(response.body.presence || "{}") — a malformed
        // body raises (the gem's JSON::ParserError propagates).
        $body = $response->body();

        return json_decode($body === '' ? '{}' : $body, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Rails: `retryable?` — a transient failure only when the client opted
     * into retry_on_transient_errors: a connection-level exception, or an
     * HTTP 500/502/503/504.
     */
    protected function retryable(LagoHttpError|ConnectionException $error): bool
    {
        if (! $this->retryOnTransientErrors) {
            return false;
        }

        return $error instanceof ConnectionException
            || in_array((int) $error->errorCode, self::RETRYABLE_HTTP_STATUSES, true);
    }

    /** @param  array<string, string>  $headers */
    protected function request(string $method, string $encodedBody, array $headers): Response
    {
        $this->guardAddress();

        $pending = Http::withHeaders($headers)
            ->contentType('application/json')
            ->when($this->readTimeout !== null, fn ($http) => $http->timeout($this->readTimeout))
            ->when($this->openTimeout !== null, fn ($http) => $http->connectTimeout($this->openTimeout));

        $response = $pending->withBody($encodedBody, 'application/json')->send($method, $this->url);

        $code = $response->status();
        if (! in_array($code, self::RESPONSE_SUCCESS_CODES, true)) {
            throw new LagoHttpError($code, $response->body(), $this->url, $response->headers());
        }

        return $response;
    }

    /**
     * Rails: `pin_resolved_address` — resolves the host and refuses private
     * targets before the socket is opened (unless explicitly allowed).
     */
    protected function guardAddress(): void
    {
        if (! $this->blockPrivateAddresses || ! AddressGuard::enabled()) {
            return;
        }

        $host = parse_url($this->url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            throw new ConnectionException("no host in URL: {$this->url}");
        }

        AddressGuard::resolve($host);
    }
}
