<?php

declare(strict_types=1);

namespace App\Serializers\V1\Analytics;

/**
 * Port of Rails' V1::Analytics::MrrSerializer
 * (app/serializers/v1/analytics/mrr_serializer.rb).
 */
class MrrSerializer extends BaseSerializer
{
    /** @return array<string, mixed> */
    public function serialize(): array
    {
        return [
            'month' => $this->serializeMonth($this->row['month'] ?? null),
            'amount_cents' => $this->intOrNull($this->row['amount_cents'] ?? null),
            'currency' => $this->row['currency'] ?? null,
        ];
    }
}
