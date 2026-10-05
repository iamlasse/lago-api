<?php

declare(strict_types=1);

namespace App\Values;

/**
 * Port of Rails' PaymentProviders::CashfreeProvider::CashfreePayment (a
 * Data define) — the normalized Cashfree payment-link event the webhook
 * handlers pass into the payment-status update services.
 */
final class CashfreePayment
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly array $metadata = [],
    ) {}

    public function metadataValue(string $key): mixed
    {
        return $this->metadata[$key] ?? null;
    }
}
