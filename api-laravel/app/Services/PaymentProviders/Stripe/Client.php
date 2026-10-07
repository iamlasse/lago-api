<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Stripe;

use Exception;
use Throwable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\ConnectionException;

/**
 * Stripe REST client used in place of the stripe gem (no composer
 * dependencies in this port).
 *
 * Header/param fidelity ported from stripe 6.5.0 usage in the Rails app:
 *  - base URL https://api.stripe.com, paths under /v1;
 *  - `Authorization: Bearer <secret_key>` (each organization's own key —
 *    the gem's per-call `api_key:` option);
 *  - request bodies are application/x-www-form-urlencoded with Rails-gem
 *    bracket encoding (address[city]=…, metadata[lago_customer_id]=…,
 *    payment_method_types[]=card — nested arrays repeat the `[]` key);
 *  - `Idempotency-Key` header where Rails passes idempotency_key:
 *    ("payment-{payment.id}", "payment-intent-{payment_intent.id}",
 *    [customer.id, customer.updated_at.to_i].join("-"));
 *  - `Stripe-Version` header — Rails pins Stripe.api_version
 *    (ENV STRIPE_API_VERSION, default 2025-04-30.basil);
 *  - error responses ({error: {type, message, code, ...}}) map onto the
 *    gem's exception taxonomy so the service-layer rescues port verbatim:
 *    401/authentication_error -> Client\AuthenticationError,
 *    402/card_error -> Client\CardError,
 *    429/rate_limit_error -> Client\RateLimitError,
 *    idempotency_error -> Client\IdempotencyError,
 *    connection failures -> Client\ApiConnectionError,
 *    everything else -> Client\InvalidRequestError (Client\StripeError base).
 */
final readonly class Client
{
    /** Rails: Stripe.api_version default (config/initializers/stripe.rb). */
    public const string API_VERSION = '2025-04-30.basil';

    public function __construct(
        private string $apiKey,
        private ?string $idempotencyKey = null,
    ) {}

    /** @param array<string, mixed> $params top-level Stripe params (nested arrays are bracket-encoded) */
    public function post(string $path, array $params = []): Response
    {
        return $this->request()->withBody($this->formBody($params), 'application/x-www-form-urlencoded')
            ->post('https://api.stripe.com'.$path);
    }

    public function get(string $path, array $params = []): Response
    {
        $query = $this->formBody($params);

        return $this->request()->get('https://api.stripe.com'.$path.($query !== '' ? '?'.$query : ''));
    }

    public function delete(string $path, array $params = []): Response
    {
        return $this->request()->delete('https://api.stripe.com'.$path, $params);
    }

    /**
     * Executes a call and maps Stripe's error envelope onto the gem's
     * exception taxonomy (see the class docblock).
     *
     * @return array<string, mixed> the decoded response object
     */
    public function call(string $method, string $path, array $params = []): array
    {
        try {
            $response = match ($method) {
                'get' => $this->get($path, $params),
                'delete' => $this->delete($path, $params),
                default => $this->post($path, $params),
            };
        } catch (ConnectionException $e) {
            throw new ApiConnectionError($e->getMessage(), previous: $e);
        }

        if ($response->successful()) {
            /** @var array<string, mixed> */
            return $response->json() ?? [];
        }

        throw $this->errorFromResponse($response);
    }

    /**
     * Bracket-encodes nested params like the stripe gem: a hash becomes
     * key[subkey]=value (recursively), a list repeats the key with empty
     * brackets — key[]=value&key[]=value.
     *
     * @param  array<string, mixed>  $params
     * @return list<array{0: string, 1: string}> [name, value] pairs (keys may repeat)
     */
    public function encode(array $params, string $prefix = ''): array
    {
        $out = [];

        foreach ($params as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix.'['.$key.']';

            if (is_array($value)) {
                if ($value === []) {
                    continue;
                }

                if (array_is_list($value)) {
                    foreach ($value as $item) {
                        if (is_array($item)) {
                            $out = [...$out, ...$this->encode($item, $name.'[]')];

                            continue;
                        }

                        if ($item === null) {
                            continue;
                        }

                        $out[] = [$name.'[]', is_bool($item) ? ($item ? 'true' : 'false') : (string) $item];
                    }

                    continue;
                }

                $out = [...$out, ...$this->encode($value, $name)];

                continue;
            }

            // The stripe gem drops nil params entirely and stringifies
            // booleans as true/false.
            if ($value === null) {
                continue;
            }

            $out[] = [$name, is_bool($value) ? ($value ? 'true' : 'false') : (string) $value];
        }

        return $out;
    }

    /** Builds the urlencoded form body (repeated `key[]` pairs preserved). */
    public function formBody(array $params): string
    {
        return implode('&', array_map(
            fn (array $pair): string => rawurlencode($pair[0]).'='.rawurlencode($pair[1]),
            $this->encode($params),
        ));
    }

    private function errorFromResponse(Response $response): StripeError
    {
        $error = $response->json('error') ?? [];
        $message = (string) ($error['message'] ?? $response->body());
        $code = $error['code'] ?? null;
        $type = $error['type'] ?? null;
        $paymentIntentId = $error['payment_intent']['id'] ?? null;

        return match (true) {
            $response->status() === 401 || $type === 'authentication_error' => new AuthenticationError($message, $code, $paymentIntentId),
            $response->status() === 403 || $type === 'permission_error' => new PermissionError($message, $code, $paymentIntentId),
            $response->status() === 402 || $type === 'card_error' => new CardError($message, $code, $paymentIntentId),
            $response->status() === 429 || $type === 'rate_limit_error' => new RateLimitError($message, $code, $paymentIntentId),
            $type === 'idempotency_error' => new IdempotencyError($message, $code, $paymentIntentId),
            default => new InvalidRequestError($message, $code, $paymentIntentId),
        };
    }

    private function request(): PendingRequest
    {
        // asForm: Stripe's REST API takes application/x-www-form-urlencoded
        // bodies (the stripe gem's default) — never JSON.
        $request = Http::asForm()->withHeaders([
            'Authorization' => 'Bearer '.$this->apiKey,
            'Stripe-Version' => env('STRIPE_API_VERSION', self::API_VERSION),
            'User-Agent' => 'Stripe/v1 php-bindings/6.5.0-lago',
        ]);

        if ($this->idempotencyKey !== null) {
            $request = $request->withHeaders(['Idempotency-Key' => $this->idempotencyKey]);
        }

        return $request;
    }
}

/**
 * Base of the stripe-gem exception taxonomy port (StripeError).
 */
class StripeError extends Exception
{
    public function __construct(
        string $message,
        public readonly ?string $stripeCode = null,
        public readonly ?string $paymentIntentId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** The Stripe error code (e.g. "card_declined", "amount_too_small"). */
    public function code(): ?string
    {
        return $this->stripeCode;
    }
}

final class AuthenticationError extends StripeError {}

final class PermissionError extends StripeError {}

final class CardError extends StripeError {}

final class RateLimitError extends StripeError {}

final class IdempotencyError extends StripeError {}

final class ApiConnectionError extends StripeError {}

final class InvalidRequestError extends StripeError {}
