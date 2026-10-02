<?php

declare(strict_types=1);

namespace App\Serializers\V1\Invoices;

use App\Serializers\Base\ModelSerializer;
use App\Serializers\V1\Concerns\FormatsDatetime;

/**
 * Port of Rails' V1::Invoices::AppliedTaxSerializer
 * (app/serializers/v1/invoices/applied_tax_serializer.rb).
 */
class AppliedTaxSerializer extends ModelSerializer
{
    use FormatsDatetime;

    /** @return array<string, mixed> */
    public function serialize(): array
    {
        return [
            'lago_id' => $this->model->id,
            'lago_invoice_id' => $this->model->invoice_id,
            'lago_tax_id' => $this->model->tax_id,
            'tax_name' => $this->model->tax_name,
            'tax_code' => $this->model->tax_code,
            'tax_rate' => $this->model->tax_rate,
            'tax_description' => $this->model->tax_description,
            'amount_cents' => $this->model->amount_cents,
            'amount_currency' => $this->model->amount_currency,
            'fees_amount_cents' => $this->model->fees_amount_cents,
            'created_at' => $this->serializeDatetime($this->model->created_at),
        ];
    }
}
