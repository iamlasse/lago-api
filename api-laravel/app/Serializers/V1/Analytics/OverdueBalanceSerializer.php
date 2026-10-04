<?php

declare(strict_types=1);

namespace App\Serializers\V1\Analytics;

/**
 * Port of Rails' V1::Analytics::OverdueBalanceSerializer
 * (app/serializers/v1/analytics/overdue_balance_serializer.rb) — the
 * `lago_invoice_ids` jsonb_agg column comes back as a JSON array-of-arrays
 * string, flattened one level.
 */
class OverdueBalanceSerializer extends BaseSerializer
{
    /** @return array<string, mixed> */
    public function serialize(): array
    {
        return [
            'month' => $this->serializeMonth($this->row['month'] ?? null),
            'amount_cents' => $this->intOrNull($this->row['amount_cents'] ?? null),
            'currency' => $this->row['currency'] ?? null,
            'lago_invoice_ids' => $this->flattenInvoiceIds($this->row['lago_invoice_ids'] ?? null),
            'billing_entity_id' => $this->row['billing_entity_id'] ?? null,
        ];
    }

    /**
     * Port of `JSON.parse(model["lago_invoice_ids"]).flatten`.
     *
     * @return list<mixed>
     */
    private function flattenInvoiceIds(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $decoded = is_array($value) ? $value : json_decode((string) $value, true);

        if (! is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $entry) {
            if (is_array($entry)) {
                foreach ($entry as $id) {
                    $out[] = $id;
                }
            } else {
                $out[] = $entry;
            }
        }

        return $out;
    }
}
