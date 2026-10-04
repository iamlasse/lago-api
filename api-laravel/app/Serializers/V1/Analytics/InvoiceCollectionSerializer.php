<?php

declare(strict_types=1);

namespace App\Serializers\V1\Analytics;

/**
 * Port of Rails' V1::Analytics::InvoiceCollectionSerializer
 * (app/serializers/v1/analytics/invoice_collection_serializer.rb).
 */
class InvoiceCollectionSerializer extends BaseSerializer
{
    /** @return array<string, mixed> */
    public function serialize(): array
    {
        return [
            'month' => $this->serializeMonth($this->row['month'] ?? null),
            'payment_status' => $this->row['payment_status'] ?? null,
            'invoices_count' => $this->intOrNull($this->row['invoices_count'] ?? null),
            'amount_cents' => $this->intOrNull($this->row['amount_cents'] ?? null),
            'currency' => $this->row['currency'] ?? null,
        ];
    }
}
