<?php

declare(strict_types=1);

namespace App\Services\Webhooks\Invoices;

use App\Services\Webhooks\BaseService;

/**
 * Port of Rails' Webhooks::Invoices::DraftedService
 * (app/services/webhooks/invoices/drafted_service.rb) — Rails includes
 * %i[customer subscriptions billing_periods fees credits applied_taxes
 * error_details] via V1::InvoiceSerializer.
 */
class DraftedService extends BaseService
{
    use SerializesInvoice;

    protected function webhookType(): string
    {
        return 'invoice.drafted';
    }

    protected function objectType(): string
    {
        return 'invoice';
    }
}
