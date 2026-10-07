<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Serializers\Base\ModelSerializer;

/**
 * Port of Rails' V1::InvoiceCustomSectionSerializer
 * (app/serializers/v1/invoice_custom_section_serializer.rb).
 *
 * @phpstan-extends ModelSerializer<\App\Models\InvoiceCustomSection>
 */
class InvoiceCustomSectionSerializer extends ModelSerializer
{
    /** @return array<string, mixed> */
    public function serialize(): array
    {
        return [
            'lago_id' => $this->model->id,
            'code' => $this->model->code,
            'name' => $this->model->name,
            'description' => $this->model->description,
            'details' => $this->model->details,
            'display_name' => $this->model->display_name,
            'organization_id' => $this->model->organization_id,
        ];
    }
}
