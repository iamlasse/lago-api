<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Serializers\Base\ModelSerializer;

/**
 * Port of Rails' V1::AppliedInvoiceCustomSectionSerializer
 * (app/serializers/v1/applied_invoice_custom_section_serializer.rb) — a
 * customer / billing entity selection row with its nested section.
 *
 * @phpstan-extends ModelSerializer<\App\Models\CustomerAppliedInvoiceCustomSection>
 */
class AppliedInvoiceCustomSectionSerializer extends ModelSerializer
{
    /** @return array<string, mixed> */
    public function serialize(): array
    {
        return [
            'lago_id' => $this->model->id,
            'invoice_custom_section_id' => $this->model->invoice_custom_section_id,
            'created_at' => $this->serializeDatetime($this->model->created_at),
            'invoice_custom_section' => (new InvoiceCustomSectionSerializer(
                $this->model->invoiceCustomSection,
            ))->serialize(),
        ];
    }
}
