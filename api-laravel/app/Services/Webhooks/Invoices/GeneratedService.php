<?php

declare(strict_types=1);

namespace App\Services\Webhooks\Invoices;

use App\Services\Webhooks\BaseService;

/**
 * Port of Rails' Webhooks::Invoices::GeneratedService
 * (app/services/webhooks/invoices/generated_service.rb) — the
 * invoice.generated webhook, sent after the PDF is generated. Rails
 * includes %i[customer] via V1::InvoiceSerializer.
 */
class GeneratedService extends BaseService
{
    use SerializesInvoice;

    protected function webhookType(): string
    {
        return 'invoice.generated';
    }

    protected function objectType(): string
    {
        return 'invoice';
    }
}
