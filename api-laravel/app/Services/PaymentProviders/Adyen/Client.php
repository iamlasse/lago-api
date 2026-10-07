<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Adyen;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Adyen Checkout REST client used in place of the adyen-ruby-api-library
 * (7.3.1) gem — no composer dependencies in this port.
 *
 * URL fidelity (gem's Adyen::Client#service_url, Checkout DEFAULT_VERSION
 * 70):
 *  - test:  https://checkout-test.adyen.com/v70/{action}
 *  - live:  https://{live_prefix}-checkout-live.adyen.com/checkout/v70/{action}
 * Actions used by the ported services: payments, paymentMethods,
 * paymentLinks, payments/{pspReference}/cancels (ModificationApi).
 *
 * Auth: `X-API-Key` header (gem: Checkout service requires an API key).
 * The gem maps 401 responses to ::Adyen::AuthenticationError and 422-style
 * request errors to ::Adyen::ValidationError; `call()` reproduces that
 * mapping and otherwise returns the decoded body with the HTTP status.
 */
final readonly class Client
{
    /** Gem: Adyen::Checkout DEFAULT_VERSION. */
    public const int API_VERSION = 70;

    public function __construct(
        private string $apiKey,
        private string $environment, // 'test' | 'live'
        private ?string $livePrefix = null,
    ) {}

    /**
     * Rails: handle_adyen_response (Lago::Adyen::ErrorHandlable) — an HTTP
     * status over 400 becomes an ::Adyen::AdyenError built from the
     * response's message/errorType.
     */
    public static function errorFromResponse(int $status, array $response): AdyenError
    {
        return new AdyenError(
            msg: (string) ($response['message'] ?? 'Unknown Adyen error'),
            code: (string) ($response['errorType'] ?? 'internal_error'),
        );
    }

    public static function responseFailed(int $status): bool
    {
        return $status > 400;
    }

    public static function response(Response $response): array
    {
        /** @var array<string, mixed> */
        return $response->json() ?? [];
    }

    public function url(string $action): string
    {
        if ($this->environment === 'live') {
            $prefix = (string) $this->livePrefix;

            return "https://{$prefix}-checkout-live.adyen.com/checkout/v".self::API_VERSION."/{$action}";
        }

        return 'https://checkout-test.adyen.com/v'.self::API_VERSION.'/'.$action;
    }

    /**
     * Executes a Checkout call; 401 raises AuthenticationError, any other
     * status is returned to the caller alongside the decoded body (the
     * Rails services branch on `adyen_result.status`).
     *
     * @param  array<string, mixed>  $body
     * @return array{0: int, 1: array<string, mixed>} [status, response]
     */
    public function call(string $method, string $action, array $body = [], array $headers = []): array
    {
        $request = Http::withHeaders(array_merge([
            'X-API-Key' => $this->apiKey,
            'Content-Type' => 'application/json',
        ], $headers))->acceptJson();

        $response = match (mb_strtolower($method)) {
            'get' => $request->get($this->url($action)),
            'patch' => $request->patch($this->url($action), $body),
            default => $request->post($this->url($action), $body),
        };

        if ($response->status() === 401) {
            throw new AuthenticationError(
                msg: (string) ($response->json('message') ?? 'Unauthorized'),
                code: (string) ($response->json('errorType') ?? 'authentication'),
            );
        }

        /** @var array<string, mixed> $json */
        $json = $response->json() ?? [];

        return [$response->status(), $json];
    }
}
