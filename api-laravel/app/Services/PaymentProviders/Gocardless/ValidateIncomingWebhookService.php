<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Gocardless;

use JsonException;
use App\Services\BaseResult;
use App\Services\BaseService;

/**
 * Port of the gocardless_pro gem's GoCardlessPro::Webhook.parse signature
 * verification as used by Rails'
 * PaymentProviders::Gocardless::HandleIncomingWebhookService:
 * the expected signature is the hex HMAC-SHA256 of the raw request body
 * with the endpoint's webhook secret, compared against the
 * Webhook-Signature header (constant-time). A JSON parse failure or a
 * mismatch yields a "webhook_error" ServiceFailure (the webhook route
 * answers 400).
 */
class ValidateIncomingWebhookService extends BaseService
{
    public function __construct(
        private readonly string $body,
        private readonly ?string $signatureHeader,
        private readonly ?string $webhookSecret,
    ) {
        parent::__construct();
    }

    /** Gem: GoCardlessPro::Webhook.signature_valid? — hex HMAC-SHA256 of the body. */
    public static function signatureValid(string $body, ?string $signatureHeader, string $secret): bool
    {
        if ($signatureHeader === null || $signatureHeader === '' || $secret === '') {
            return false;
        }

        $computed = hash_hmac('sha256', $body, $secret);

        return hash_equals($computed, $signatureHeader);
    }

    /** @return array<int, array<string, mixed>> the parsed `events` on success */
    public function execute(): BaseResult
    {
        $result = static::makeResult('events');

        if (! self::signatureValid($this->body, $this->signatureHeader, (string) $this->webhookSecret)) {
            return $result->serviceFailure(code: 'webhook_error', message: 'Invalid signature');
        }

        try {
            $parsed = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($parsed) || ! is_array($parsed['events'] ?? null)) {
                throw new JsonException('Invalid payload');
            }
        } catch (JsonException) {
            return $result->serviceFailure(code: 'webhook_error', message: 'Invalid payload');
        }

        $result->events = $parsed['events'];

        return $result;
    }
}
