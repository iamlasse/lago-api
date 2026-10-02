<?php

declare(strict_types=1);

namespace App\Serializers\V1\Fees;

use App\Serializers\Base\ModelSerializer;

/**
 * Port of Rails' V1::Fees::AppliedTaxSerializer
 * (app/serializers/v1/fees/applied_tax_serializer.rb).
 */
class AppliedTaxSerializer extends ModelSerializer
{
    /** @return array<string, mixed> */
    public function serialize(): array
    {
        return [
            'lago_id' => $this->model->id,
            'lago_fee_id' => $this->model->fee_id,
            'lago_tax_id' => $this->model->tax_id,
            'tax_name' => $this->model->tax_name,
            'tax_code' => $this->model->tax_code,
            'tax_rate' => $this->model->tax_rate,
            'tax_description' => $this->model->tax_description,
            'amount_cents' => $this->model->amount_cents,
            'amount_currency' => $this->model->amount_currency,
            'created_at' => \Carbon\CarbonImmutable::instance($this->model->created_at)->toIso8601String(),
        ];
    }
}
