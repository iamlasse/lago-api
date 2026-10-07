<?php

declare(strict_types=1);

namespace App\Values;

/**
 * Port of Rails' PaymentProviders::FlutterwaveProvider::FlutterwavePayment
 * (a Data define) — the normalized Flutterwave transaction the webhook
 * handlers pass into the payment-status update services.
 */
final readonly class FlutterwavePayment
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $id,
        public string $status,
        public array $metadata = [],
    ) {}

    public function metadataValue(string $key): mixed
    {
        return $this->metadata[$key] ?? null;
    }
}
