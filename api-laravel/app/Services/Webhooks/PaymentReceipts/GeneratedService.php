<?php

declare(strict_types=1);

namespace App\Services\Webhooks\PaymentReceipts;

use App\Services\Webhooks\BaseService;
use App\Serializers\V1\PaymentReceiptSerializer;

/**
 * Port of Rails' Webhooks::PaymentReceipts::GeneratedService
 * (app/services/webhooks/payment_receipts/generated_service.rb) — the PDF
 * generation completion event (PaymentReceipts::GeneratePdfService).
 */
class GeneratedService extends BaseService
{
    /** @return array<string, mixed> */
    protected function objectSerializer(): array
    {
        return (new PaymentReceiptSerializer(
            $this->object,
            ['root_name' => 'payment_receipt'],
        ))->serialize();
    }

    protected function webhookType(): string
    {
        return 'payment_receipt.generated';
    }

    protected function objectType(): string
    {
        return 'payment_receipt';
    }
}
