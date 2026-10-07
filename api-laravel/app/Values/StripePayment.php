<?php

declare(strict_types=1);

namespace App\Values;

/**
 * Port of Rails' PaymentProviders::StripeProvider::StripePayment (a Data
 * define) — the normalized Stripe payment object the webhook handlers pass
 * into the payment-status update services.
 */
final readonly class StripePayment
{
    public function __construct(
        public string $id,
        public string $status,
        /** @var array<string, mixed> */
        public array $metadata = [],
        public ?string $errorCode = null,
    ) {
        /** @var array<string, mixed> $metadata */
    }

    public function metadataValue(string $key): mixed
    {
        return $this->metadata[$key] ?? null;
    }
}
