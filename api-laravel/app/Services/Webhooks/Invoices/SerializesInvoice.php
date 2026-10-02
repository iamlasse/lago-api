<?php

declare(strict_types=1);

namespace App\Services\Webhooks\Invoices;

use App\Services\Webhooks\BaseService;
use DateTimeInterface;

/**
 * Shared invoice webhook payload builder.
 *
 * TODO(integration): swap to the ported V1::InvoiceSerializer once the
 * invoices slice lands — Rails builds this payload with
 * `V1::InvoiceSerializer.new(object, root_name: "invoice", includes: ...)`.
 * The top-level scalar fields below match Rails' InvoiceSerializer#serialize
 * head; the relation includes (customer, subscriptions, billing_periods,
 * fees, credits, applied_taxes, error_details, applied_invoice_custom_sections)
 * are emitted as empty collections until then.
 *
 * @mixin BaseService
 */
trait SerializesInvoice
{
    /** @return array<string, mixed> */
    protected function objectSerializer(): array
    {
        $invoice = $this->object;

        $payload = [
            'lago_id' => $invoice->id,
            'billing_entity_code' => $invoice->billingEntity?->code,
            'sequential_id' => $invoice->sequential_id,
            'number' => $invoice->number,
            'purchase_order_number' => $invoice->purchase_order_number,
            'issuing_date' => $this->serializeDate($invoice->issuing_date),
            'payment_due_date' => $this->serializeDate($invoice->payment_due_date),
            'net_payment_term' => $invoice->net_payment_term,
            'invoice_type' => $invoice->getRawOriginal('invoice_type'),
            'status' => $invoice->getRawOriginal('status'),
            'payment_status' => $invoice->getRawOriginal('payment_status'),
            'payment_dispute_lost_at' => $this->serializeDatetime($invoice->payment_dispute_lost_at),
            'payment_overdue' => $invoice->payment_overdue,
            'currency' => $invoice->currency,
            'fees_amount_cents' => $invoice->fees_amount_cents,
            'taxes_amount_cents' => $invoice->taxes_amount_cents,
            'progressive_billing_credit_amount_cents' => $invoice->progressive_billing_credit_amount_cents,
            'coupons_amount_cents' => $invoice->coupons_amount_cents,
            'credit_notes_amount_cents' => $invoice->credit_notes_amount_cents,
            'sub_total_excluding_taxes_amount_cents' => $invoice->sub_total_excluding_taxes_amount_cents,
            'sub_total_including_taxes_amount_cents' => $invoice->sub_total_including_taxes_amount_cents,
            'total_amount_cents' => $invoice->total_amount_cents,
            'total_due_amount_cents' => $invoice->totalDueAmountCents(),
            'total_paid_amount_cents' => $invoice->total_paid_amount_cents,
            'total_offsetted_credit_note_amount_cents' => $invoice->offset_amount_cents,
            'prepaid_credit_amount_cents' => $invoice->prepaid_credit_amount_cents,
            'prepaid_granted_credit_amount_cents' => $invoice->prepaid_granted_credit_amount_cents,
            'prepaid_purchased_credit_amount_cents' => $invoice->prepaid_purchased_credit_amount_cents,
            // TODO(port): file_url / xml_url / web_url need the invoice file
            // storage and front URL helpers.
            'file_url' => null,
            'xml_url' => null,
            'web_url' => null,
            'version_number' => $invoice->version_number,
            'self_billed' => $invoice->self_billed,
            'created_at' => $this->serializeDatetime($invoice->created_at),
            'updated_at' => $this->serializeDatetime($invoice->updated_at),
            'voided_at' => $this->serializeDatetime($invoice->voided_at),
        ];

        // Rails includes (customer, subscriptions, billing_periods, fees,
        // credits, applied_taxes, error_details for drafted invoices,
        // applied_invoice_custom_sections) are omitted until the V1
        // InvoiceSerializer port exists.
        return $payload;
    }

    /** Rails: `iso8601` on a date column (Y-m-d). */
    protected function serializeDate(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : null;
    }

    /** Rails: `iso8601` on a datetime column. */
    protected function serializeDatetime(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format(DateTimeInterface::ATOM) : null;
    }
}
