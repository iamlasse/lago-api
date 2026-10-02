<?php

declare(strict_types=1);

namespace App\Services\Webhooks\Invoices;

use App\Services\Webhooks\BaseService;

/**
 * Port of Rails' Webhooks::Invoices::CreatedService
 * (app/services/webhooks/invoices/created_service.rb) — Rails includes
 * %i[customer subscriptions billing_periods fees credits applied_taxes
 * applied_invoice_custom_sections] via V1::InvoiceSerializer.
 */
class CreatedService extends BaseService
{
    use SerializesInvoice;

    protected function webhookType(): string
    {
        return 'invoice.created';
    }

    protected function objectType(): string
    {
        return 'invoice';
    }
}
