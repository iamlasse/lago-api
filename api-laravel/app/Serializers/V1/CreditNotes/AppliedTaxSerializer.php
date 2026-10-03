<?php

declare(strict_types=1);

namespace App\Serializers\V1\CreditNotes;

use App\Serializers\Base\ModelSerializer;
use App\Serializers\V1\Concerns\FormatsDatetime;

/**
 * Port of Rails' V1::CreditNotes::AppliedTaxSerializer
 * (app/serializers/v1/credit_notes/applied_tax_serializer.rb).
 */
class AppliedTaxSerializer extends ModelSerializer
{
    use FormatsDatetime;

    /** @return array<string, mixed> */
    public function serialize(): array
    {
        return [
            'lago_id' => $this->model->id,
            'lago_credit_note_id' => $this->model->credit_note_id,
            'lago_tax_id' => $this->model->tax_id,
            'tax_name' => $this->model->tax_name,
            'tax_code' => $this->model->tax_code,
            'tax_rate' => $this->model->tax_rate,
            'tax_description' => $this->model->tax_description,
            'base_amount_cents' => $this->model->base_amount_cents,
            'amount_cents' => $this->model->amount_cents,
            'amount_currency' => $this->model->amount_currency,
            'created_at' => $this->serializeDatetime($this->model->created_at),
        ];
    }
}
