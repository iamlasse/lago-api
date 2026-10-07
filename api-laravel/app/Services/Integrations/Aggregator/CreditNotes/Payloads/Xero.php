<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\CreditNotes\Payloads;

use App\Models\CreditNoteItem;

/**
 * Port of Rails' Integrations::Aggregator::CreditNotes::Payloads::Xero
 * (…/aggregator/credit_notes/payloads/xero.rb) — the item_code rename over
 * the shared credit note shape.
 */
final class Xero extends BasePayload
{
    /**
     * @return array<string, mixed>
     */
    protected function item(CreditNoteItem $credit_note_item): array
    {
        $baseItem = parent::item($credit_note_item);

        $baseItem['item_code'] = $baseItem['external_id'] ?? null;
        unset($baseItem['external_id']);

        return $baseItem;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function coupons(): array
    {
        return array_map(function (array $coupon) {
            $coupon['item_code'] = $coupon['external_id'] ?? null;
            unset($coupon['external_id']);

            return $coupon;
        }, parent::coupons());
    }
}
