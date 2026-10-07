<?php

declare(strict_types=1);

namespace App\Services\DataExports\Csv;

use App\Models\CreditNote;
use App\Models\DataExportPart;
use App\Serializers\V1\CreditNoteSerializer;

/**
 * Port of Rails' DataExports::Csv::CreditNotes
 * (app/services/data_exports/csv/credit_notes.rb) — one row per credit
 * note, serialized through V1::CreditNoteSerializer (includes: customer).
 */
class CreditNotes extends BaseCsvService
{
    public const array BASE_HEADERS = [
        'lago_id',
        'sequential_id',
        'partner_billing',
        'issuing_date',
        'customer_lago_id',
        'customer_external_id',
        'customer_name',
        'customer_email',
        'customer_country',
        'customer_tax_identification_number',
        'number',
        'invoice_number',
        'purchase_order_number',
        'credit_status',
        'refund_status',
        'reason',
        'description',
        'currency',
        'total_amount_cents',
        'taxes_amount_cents',
        'sub_total_excluding_taxes_amount_cents',
        'coupons_adjustment_amount_cents',
        'offset_amount_cents',
        'credit_amount_cents',
        'balance_amount_cents',
        'refund_amount_cents',
        'file_url',
    ];

    /** @return list<string> */
    protected static function buildHeaders(DataExportPart $dataExportPart): array
    {
        $headers = self::BASE_HEADERS;

        $organization = $dataExportPart->dataExport->organization;

        if ($organization !== null && $organization->billingEntities()->count() > 1) {
            $headers[] = 'billing_entity_code';
        }

        return $headers;
    }

    protected function collection(): iterable
    {
        // Rails: CreditNote.includes(:customer).find(ids).
        return CreditNote::query()
            ->with('customer')
            ->findMany($this->dataExportPart->object_ids ?? []);
    }

    protected function serializeItem(mixed $item, $stream): void
    {
        $serialized = (new CreditNoteSerializer(
            $item,
            ['includes' => ['customer']],
        ))->serialize();

        $row = [
            $serialized['lago_id'],
            $serialized['sequential_id'],
            $serialized['self_billed'],
            $serialized['issuing_date'],
            $serialized['customer']['lago_id'] ?? null,
            $serialized['customer']['external_id'] ?? null,
            $serialized['customer']['name'] ?? null,
            $serialized['customer']['email'] ?? null,
            $serialized['customer']['country'] ?? null,
            $serialized['customer']['tax_identification_number'] ?? null,
            $serialized['number'],
            $serialized['invoice_number'],
            $serialized['purchase_order_number'],
            $serialized['credit_status'],
            $serialized['refund_status'],
            $serialized['reason'],
            $serialized['description'],
            $serialized['currency'],
            $serialized['total_amount_cents'],
            $serialized['taxes_amount_cents'],
            $serialized['sub_total_excluding_taxes_amount_cents'],
            $serialized['coupons_adjustment_amount_cents'],
            $serialized['offset_amount_cents'],
            $serialized['credit_amount_cents'],
            $serialized['balance_amount_cents'],
            $serialized['refund_amount_cents'],
            $serialized['file_url'],
        ];

        if ($this->orgHasMultipleBillingEntities()) {
            $row[] = $serialized['billing_entity_code'];
        }

        $this->writeRow($stream, $row);
    }
}
