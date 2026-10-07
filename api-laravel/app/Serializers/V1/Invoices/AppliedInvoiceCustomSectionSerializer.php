<?php

declare(strict_types=1);

namespace App\Serializers\V1\Invoices;

use App\Serializers\Base\ModelSerializer;

/**
 * Port of Rails' V1::Invoices::AppliedInvoiceCustomSectionSerializer
 * (app/serializers/v1/invoices/applied_invoice_custom_section_serializer.rb) —
 * the per-invoice section snapshot.
 *
 * @phpstan-extends ModelSerializer<\App\Models\AppliedInvoiceCustomSection>
 */
class AppliedInvoiceCustomSectionSerializer extends ModelSerializer
{
    /** @return array<string, mixed> */
    public function serialize(): array
    {
        return [
            'lago_id' => $this->model->id,
            'lago_invoice_id' => $this->model->invoice_id,
            'code' => $this->model->code,
            'details' => $this->model->details,
            'display_name' => $this->model->display_name,
            'created_at' => $this->serializeDatetime($this->model->created_at),
        ];
    }
}
