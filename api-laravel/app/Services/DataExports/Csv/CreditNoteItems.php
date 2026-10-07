<?php

declare(strict_types=1);

namespace App\Services\DataExports\Csv;

use App\Models\CreditNoteItem;
use App\Models\DataExportPart;
use App\Serializers\V1\CreditNoteItemSerializer;

/**
 * Port of Rails' DataExports::Csv::CreditNoteItems
 * (app/services/data_exports/csv/credit_note_items.rb) — one row per credit
 * note item of the part's credit notes, serialized through
 * V1::CreditNoteItemSerializer.
 */
class CreditNoteItems extends BaseCsvService
{
    public const array BASE_HEADERS = [
        'credit_note_lago_id',
        'credit_note_number',
        'credit_note_invoice_number',
        'credit_note_issuing_date',
        'credit_note_item_lago_id',
        'credit_note_item_fee_lago_id',
        'credit_note_item_currency',
        'credit_note_item_amount_cents',
    ];

    /** @return list<string> */
    protected static function buildHeaders(DataExportPart $dataExportPart): array
    {
        return self::BASE_HEADERS;
    }

    protected function collection(): iterable
    {
        // Rails: CreditNoteItem.includes(:credit_note, :fee)
        //   .where(credit_note_id: ids).
        return CreditNoteItem::query()
            ->with(['creditNote', 'fee'])
            ->whereIn('credit_note_id', $this->dataExportPart->object_ids ?? [])
            ->get();
    }

    protected function serializeItem(mixed $item, $stream): void
    {
        $serialized = (new CreditNoteItemSerializer($item))->serialize();

        $creditNote = $item->creditNote;

        $this->writeRow($stream, [
            $creditNote->id,
            $creditNote->number,
            $creditNote->invoice->number,
            // Rails: issuing_date.iso8601.
            optional($creditNote->issuing_date)->toDateString(),
            $serialized['lago_id'],
            $serialized['fee']['lago_id'] ?? null,
            $serialized['amount_currency'],
            $serialized['amount_cents'],
        ]);
    }
}
