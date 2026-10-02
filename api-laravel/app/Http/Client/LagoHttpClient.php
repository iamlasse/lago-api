<?php

declare(strict_types=1);

namespace App\Http\Client;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Port of LagoHttpClient::Client
 * (lib/lago_http_client/lago_http_client/client.rb), built on Laravel's HTTP
 * client so tests can stub delivery with Http::fake().
 *
 * Only the surface the webhook pipeline uses is ported (post_with_response);
 * the gem's multipart / SSE-stream / session-cookie methods are out of scope
 * until their consumers are ported.
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

    public function __construct(
        protected readonly string $url,
        protected readonly ?int $openTimeout = null,
        protected readonly ?int $readTimeout = null,
        protected readonly ?int $writeTimeout = null,
        protected readonly bool $blockPrivateAddresses = false,
    ) {}

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

    /** Rails: `post_with_response` with an already-encoded body. */
    public function postRawWithResponse(string $encodedBody, array $headers): Response
    {
        return $this->request('POST', $encodedBody, $headers);
    }

    /** @param  array<string, string>  $headers */
    protected function request(string $method, string $encodedBody, array $headers): Response
    {
        $this->guardAddress();

        $pending = Http::withHeaders($headers)
            ->contentType('application/json')
            ->when($this->readTimeout !== null, fn ($http) => $http->timeout($this->readTimeout))
            ->when($this->openTimeout !== null, fn ($http) => $http->connectTimeout($this->openTimeout));

        $response = $pending->withBody($encodedBody, 'application/json')->send($method, [$this->url]);

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
