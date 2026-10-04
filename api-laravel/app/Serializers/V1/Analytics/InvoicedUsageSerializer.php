<?php

declare(strict_types=1);

namespace App\Serializers\V1\Analytics;

/**
 * Port of Rails' V1::Analytics::InvoicedUsageSerializer
 * (app/serializers/v1/analytics/invoiced_usage_serializer.rb).
 */
class InvoicedUsageSerializer extends BaseSerializer
{
    /** @return array<string, mixed> */
    public function serialize(): array
    {
        return [
            'month' => $this->serializeMonth($this->row['month'] ?? null),
            'code' => $this->row['code'] ?? null,
            'currency' => $this->row['currency'] ?? null,
            'amount_cents' => $this->intOrNull($this->row['amount_cents'] ?? null),
        ];
    }
}
