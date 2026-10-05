<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\Invoices\Payloads;

use App\Models\Fee;

/**
 * Port of Rails' Integrations::Aggregator::Invoices::Payloads::Xero
 * (…/aggregator/invoices/payloads/xero.rb) — the item_code rename, the
 * grouped-by description suffix and the precise-unit rounding split.
 */
final class Xero extends BasePayload
{
    /**
     * @return list<array<string, mixed>>
     */
    public function body(): array
    {
        return array_map(
            fn (array $invoicePayload) => array_merge($invoicePayload, [
                'reference' => $this->invoice->purchase_order_number,
            ]),
            parent::body(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function item(Fee $fee): array
    {
        $baseItem = parent::item($fee);

        $baseItem['item_code'] = $baseItem['external_id'] ?? null;
        unset($baseItem['external_id']);

        $baseItem['description'] = ((string) $baseItem['description']).$this->grouped_by_display($fee);

        if (round((float) $fee->precise_unit_amount, 2) !== (float) $fee->precise_unit_amount) {
            $baseItem['units'] = 1;
            $baseItem['precise_unit_amount'] = $this->amount($fee->amount_cents, resource: $this->invoice);
        }

        return $baseItem;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function discounts(): array
    {
        return array_map(function (array $discount) {
            $discount['item_code'] = $discount['external_id'] ?? null;
            unset($discount['external_id']);

            return $discount;
        }, parent::discounts());
    }

    /**
     * Rails: Xero accepts zero-amount line items, so the base payload's
     * zero-amount fee filter is bypassed (#2656) — all fees, ordered by
     * created_at.
     *
     * @return \Illuminate\Support\Collection<int, Fee>
     */
    protected function fees(): \Illuminate\Support\Collection
    {
        return $this->invoice->fees()->orderBy('created_at')->get();
    }

    /** Rails: Fee#grouped_by_display. */
    private function grouped_by_display(Fee $fee): string
    {
        if ($fee->typeEnum() !== \App\Enums\FeeType::Charge) {
            return '';
        }

        $values = array_values(array_filter((array) ($fee->grouped_by ?? []), fn ($v) => $v !== null && $v !== ''));

        if ($values === []) {
            return '';
        }

        return ' • '.implode(' • ', array_map(strval(...), $values));
    }
}
