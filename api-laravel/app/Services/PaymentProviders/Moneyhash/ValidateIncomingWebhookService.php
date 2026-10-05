<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Moneyhash;

use App\Services\BaseResult;
use App\Services\BaseService;

/**
 * Port of Rails' PaymentProviders::Moneyhash::ValidateIncomingWebhookService
 * — the MoneyHash-Signature header is a "t=…,v3=…" list; the expected v3
 * signature is hex HMAC-SHA256(secret, base64(json(payload)) + timestamp)
 * with the provider's signature_key. A mismatch yields a "webhook_error"
 * ServiceFailure (the webhook route answers 400).
 */
class ValidateIncomingWebhookService extends BaseService
{
    public function __construct(
        /** @var array<string, mixed> the decoded payload */
        private readonly array $payload,
        private readonly ?string $signature,
        private readonly \App\Models\PaymentProvider $provider,
    ) {
        parent::__construct();
    }

    /**
     * Rails: signature.split(",").each_with_object({}) { |part, hash|
     *   key, value = part.split("="); hash[key] = value if %w[t v3].include?(key)
     * }.values_at("t", "v3")
     *
     * @return array{0: ?string, 1: ?string} [timestamp, v3 signature]
     */
    public static function extractSignatureParts(?string $signature): array
    {
        $parts = [];

        foreach (explode(',', (string) $signature) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, null);

            if (in_array($key, ['t', 'v3'], true)) {
                $parts[$key] = $value;
            }
        }

        return [$parts['t'] ?? null, $parts['v3'] ?? null];
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult();

        [$timestamp, $v3Signature] = self::extractSignatureParts($this->signature);

        $decodedBody = base64_encode(json_encode($this->payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $toSign = $decodedBody.($timestamp ?? '');

        $calculatedSignature = hash_hmac('sha256', $toSign, (string) ($this->provider->signatureKey() ?? ''));

        if ($calculatedSignature !== $v3Signature) {
            return $result->serviceFailure(code: 'webhook_error', message: 'Invalid signature');
        }

        return $result;
    }
}
