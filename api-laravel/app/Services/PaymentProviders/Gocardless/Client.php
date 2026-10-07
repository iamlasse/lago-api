<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Gocardless;

use Throwable;
use Illuminate\Support\Facades\Http;

/**
 * GoCardless REST client used in place of the gocardless_pro gem — no
 * composer dependencies in this port.
 *
 * URL fidelity (gem's GoCardlessPro::Client): live
 * https://api.gocardless.com, sandbox https://api-sandbox.gocardless.com;
 * Bearer access-token auth; `Idempotency-Key` header where Rails passes
 * headers: {"Idempotency-Key" => ...}; request bodies use the gem's
 * `params:` envelope.
 */
final readonly class Client
{
    public function __construct(
        private string $accessToken,
        private string $environment, // 'live' | 'sandbox'
    ) {}

    /**
     * Rails: GoCardlessPro::Resources::Event — the raw webhook event hash
     * (resource_type, action, links, details, metadata).
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public static function event(array $event): array
    {
        return $event;
    }

    public function baseUrl(): string
    {
        return $this->environment === 'live'
            ? 'https://api.gocardless.com'
            : 'https://api-sandbox.gocardless.com';
    }

    /**
     * Executes a GoCardless call.
     *
     * @param  array<string, mixed>  $params
     * @return array{0: int, 1: array<string, mixed>} [status, body]
     */
    public function call(string $method, string $path, array $params = [], array $headers = []): array
    {
        $request = Http::withHeaders(array_merge([
            'Authorization' => 'Bearer '.$this->accessToken,
            'GoCardless-Version' => '2015-07-06',
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], $headers))->acceptJson();

        $body = $params === [] ? [] : ['params' => $params];

        try {
            $response = mb_strtolower($method) === 'get'
                ? $request->get($this->baseUrl().$path)
                : $request->post($this->baseUrl().$path, $body);
        } catch (Throwable $e) {
            throw new GoCardlessError($e->getMessage(), 'gocardless_error', $e);
        }

        /** @var array<string, mixed> $json */
        $json = $response->json() ?? [];

        return [$response->status(), $json];
    }
}
