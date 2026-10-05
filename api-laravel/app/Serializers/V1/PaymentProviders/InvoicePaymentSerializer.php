<?php

declare(strict_types=1);

namespace App\Serializers\V1\PaymentProviders;

use App\Models\Invoice;
use App\Serializers\Base\ModelSerializer;

/**
 * Port of Rails' V1::PaymentProviders::InvoicePaymentSerializer
 * (app/serializers/v1/payment_providers/invoice_payment_serializer.rb) —
 * the POST /invoices/:id/payment_url success payload.
 */
class InvoicePaymentSerializer extends ModelSerializer
{
    /** @return array<string, mixed> */
    public function serialize(): array
    {
        /** @var Invoice $invoice */
        $invoice = $this->model;
        $customer = $invoice->customer;

        return [
            'lago_customer_id' => $customer?->id,
            'external_customer_id' => $customer?->external_id,
            'payment_provider' => $customer?->payment_provider,
            'lago_invoice_id' => $invoice->id,
            'payment_url' => $this->options['payment_url'] ?? null,
        ];
    }
}
