<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\Invoices\Payloads;

use App\Models\IntegrationCustomer;
use App\Models\Integrations\HubspotIntegration;
use App\Services\Integrations\Aggregator\BasePayload\Failure;

/**
 * Port of Rails' Integrations::Aggregator::Invoices::Payloads::Hubspot
 * (…/aggregator/invoices/payloads/hubspot.rb) — the LagoInvoice custom
 * object body (create / update / customer association).
 */
final class Hubspot extends BasePayload
{
    public function create_body(): array
    {
        if ($this->invoice->fileUrl() === null) {
            throw new Failure('invoice.file_url missing');
        }

        return [
            'objectType' => $this->invoices_object_type_id(),
            'input' => [
                'associations' => [],
                'properties' => $this->properties(),
            ],
        ];
    }

    public function update_body(): array
    {
        if ($this->invoice->fileUrl() === null) {
            throw new Failure('invoice.file_url missing');
        }

        return [
            'objectId' => $this->integration_invoice()?->external_id,
            'objectType' => $this->invoices_object_type_id(),
            'input' => [
                'properties' => $this->properties(),
            ],
        ];
    }

    public function customer_association_body(): array
    {
        return [
            // Rails re-reads the integration for the association body
            // (integration.reload.invoices_object_type_id).
            'objectType' => $this->integration_customer->integration->fresh()->invoicesObjectTypeId(),
            'objectId' => $this->integration_invoice()?->external_id,
            'toObjectType' => $this->integration_customer->objectType(),
            'toObjectId' => $this->integration_customer->external_customer_id,
            'input' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function properties(): array
    {
        $invoice = $this->invoice;

        return [
            'lago_invoice_id' => $invoice->id,
            'lago_invoice_number' => $invoice->number,
            'lago_invoice_purchase_order_number' => $invoice->purchase_order_number,
            'lago_invoice_issuing_date' => $this->formatted_date($invoice->issuing_date),
            'lago_invoice_payment_due_date' => $this->formatted_date($invoice->payment_due_date),
            'lago_invoice_payment_overdue' => $invoice->payment_overdue,
            'lago_invoice_type' => $invoice->typeEnum()?->label(),
            'lago_invoice_status' => $invoice->statusEnum()?->label(),
            'lago_invoice_payment_status' => $invoice->paymentStatusEnum()?->label(),
            'lago_invoice_currency' => $invoice->currency,
            'lago_invoice_total_amount' => $this->total_amount(),
            'lago_invoice_total_due_amount' => $this->total_due_amount(),
            'lago_invoice_subtotal_excluding_taxes' => $this->subtotal_excluding_taxes(),
            'lago_invoice_file_url' => $invoice->fileUrl(),
            'lago_invoice_url' => $this->invoice_url(),
        ];
    }

    private function invoices_object_type_id(): mixed
    {
        $integration = $this->integration_customer->integration;

        return $integration instanceof HubspotIntegration
            ? $integration->invoicesObjectTypeId()
            : $integration->getFromSettings('invoices_object_type_id');
    }

    private function total_amount(): string
    {
        return $this->amount($this->invoice->total_amount_cents, $this->invoice);
    }

    private function total_due_amount(): string
    {
        return $this->amount($this->invoice->totalDueAmountCents(), $this->invoice);
    }

    private function subtotal_excluding_taxes(): string
    {
        return $this->amount($this->invoice->sub_total_excluding_taxes_amount_cents, $this->invoice);
    }

    /** Rails: Integrations::Aggregator::Invoices::Payloads::BasePayload#invoice_url. */
    private function invoice_url(): ?string
    {
        return $this->invoice->webUrl();
    }
}
