<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Stripe;

use App\Services\BaseResult;
use App\Services\BaseService;

/**
 * Port of Rails' PaymentProviders::Stripe::ValidateIncomingWebhookService —
 * verifies the Stripe-Signature header against the provider's webhook
 * secret, exactly like Stripe::Webhook::Signature.verify_header (gem 6.5):
 *
 *  1. the header is a comma-separated list of scheme=value pairs
 *     ("t=1699…,v1=abc…,v1=def…"); a missing timestamp or no v1 signature
 *     fails ("Unable to extract timestamp and signatures from header");
 *  2. the expected signature is hex HMAC-SHA256(secret, "{t}.{payload}"),
 *     constant-time compared against every v1 signature ("Computed
 *     payload signature does not match");
 *  3. the timestamp must be within DEFAULT_TOLERANCE (300s) of now
 *     ("Timestamp outside the tolerance window").
 *
 * Any failure yields a "webhook_error" ServiceFailure (the webhook route
 * answers 400).
 */
class ValidateIncomingWebhookService extends BaseService
{
    /** Stripe::Webhook::DEFAULT_TOLERANCE (seconds). */
    public const DEFAULT_TOLERANCE = 300;

    public function __construct(
        private readonly string $payload,
        private readonly ?string $signature,
        private readonly \App\Models\PaymentProvider $provider,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult();

        if (! $this->verifyHeader($this->payload, $this->signature, (string) $this->provider->webhookSecret())) {
            return $result->serviceFailure(code: 'webhook_error', message: 'Invalid signature');
        }

        return $result;
    }

    private function verifyHeader(string $payload, ?string $header, string $secret): bool
    {
        if ($header === null || $header === '' || $secret === '') {
            return false;
        }

        $signedPayload = null;
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            $pair = explode('=', $part, 2);

            if (count($pair) !== 2) {
                continue;
            }

            [$scheme, $value] = $pair;

            if ($scheme === 't') {
                $timestamp = $value;
            } elseif ($scheme === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            return false;
        }

        $signedPayload = $timestamp.'.'.$payload;
        $expected = hash_hmac('sha256', $signedPayload, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                $age = abs(time() - (int) $timestamp);

                return $age <= self::DEFAULT_TOLERANCE;
            }
        }

        return false;
    }
}
