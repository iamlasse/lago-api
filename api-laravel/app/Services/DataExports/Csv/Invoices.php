<?php

declare(strict_types=1);

namespace App\Services\DataExports\Csv;

use App\Models\Invoice;
use App\Models\DataExportPart;
use App\Serializers\V1\InvoiceSerializer;

/**
 * Port of Rails' DataExports::Csv::Invoices
 * (app/services/data_exports/csv/invoices.rb) — one row per invoice,
 * serialized through V1::InvoiceSerializer (includes: customer).
 */
class Invoices extends BaseCsvService
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
        'invoice_number',
        'purchase_order_number',
        'invoice_type',
        'payment_status',
        'status',
        'file_url',
        'currency',
        'fees_amount_cents',
        'coupons_amount_cents',
        'taxes_amount_cents',
        'credit_notes_amount_cents',
        'prepaid_credit_amount_cents',
        'total_amount_cents',
        'payment_due_date',
        'payment_dispute_lost_at',
        'payment_overdue',
        'total_due_amount_cents',
        'total_paid_amount_cents',
        'total_offsetted_credit_note_amount_cents',
    ];

    /** @return list<string> */
    protected static function buildHeaders(DataExportPart $dataExportPart): array
    {
        $headers = self::BASE_HEADERS;

        $organization = $dataExportPart->dataExport->organization;

        if ($organization !== null && $organization->progressiveBillingEnabled()) {
            $headers[] = 'progressive_billing_credit_amount_cents';
        }

        if ($organization !== null && $organization->billingEntities()->count() > 1) {
            $headers[] = 'billing_entity_code';
        }

        return $headers;
    }

    protected function progressiveBillingEnabled(): bool
    {
        $organization = $this->dataExportPart->dataExport->organization;

        return $organization !== null && $organization->progressiveBillingEnabled();
    }

    protected function collection(): iterable
    {
        // Rails: Invoice.preload_offset_amounts(Invoice.find(ids)) — the
        // offset-amount preload is a TODO(port) in the InvoiceSerializer
        // (total_offsetted_credit_note_amount_cents renders 0), so a plain
        // find matches the ported output.
        return Invoice::query()->findMany($this->dataExportPart->object_ids ?? []);
    }

    protected function serializeItem(mixed $item, $stream): void
    {
        $serialized = (new InvoiceSerializer(
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
            $serialized['purchase_order_number'],
            $serialized['invoice_type'],
            $serialized['payment_status'],
            $serialized['status'],
            $serialized['file_url'],
            $serialized['currency'],
            $serialized['fees_amount_cents'],
            $serialized['coupons_amount_cents'],
            $serialized['taxes_amount_cents'],
            $serialized['credit_notes_amount_cents'],
            $serialized['prepaid_credit_amount_cents'],
            $serialized['total_amount_cents'],
            $serialized['payment_due_date'],
            $serialized['payment_dispute_lost_at'],
            $serialized['payment_overdue'],
            $serialized['total_due_amount_cents'],
            $serialized['total_paid_amount_cents'],
            $serialized['total_offsetted_credit_note_amount_cents'],
        ];

        if ($this->progressiveBillingEnabled()) {
            $row[] = $serialized['progressive_billing_credit_amount_cents'];
        }

        if ($this->orgHasMultipleBillingEntities()) {
            $row[] = $serialized['billing_entity_code'];
        }

        $this->writeRow($stream, $row);
    }
}
