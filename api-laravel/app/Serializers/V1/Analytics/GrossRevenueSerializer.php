<?php

declare(strict_types=1);

namespace App\Serializers\V1\Analytics;

/**
 * Port of Rails' V1::Analytics::GrossRevenueSerializer
 * (app/serializers/v1/analytics/gross_revenue_serializer.rb).
 */
class GrossRevenueSerializer extends BaseSerializer
{
    /** @return array<string, mixed> */
    public function serialize(): array
    {
        return [
            'month' => $this->serializeMonth($this->row['month'] ?? null),
            'amount_cents' => $this->intOrNull($this->row['amount_cents'] ?? null),
            'currency' => $this->row['currency'] ?? null,
            'invoices_count' => $this->intOrNull($this->row['invoices_count'] ?? null),
            'billing_entity_id' => $this->row['billing_entity_id'] ?? null,
        ];
    }
}
