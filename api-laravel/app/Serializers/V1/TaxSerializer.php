<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Serializers\Base\ModelSerializer;
use App\Serializers\V1\Concerns\FormatsDatetime;

/**
 * Port of Rails' V1::TaxSerializer — the `*_count` fields are stubbed at 0
 * in Rails (v1 no longer computes the associations).
 */
class TaxSerializer extends ModelSerializer
{
    use FormatsDatetime;

    public function serialize(): array
    {
        return [
            'lago_id' => $this->model->id,
            'name' => $this->model->name,
            'code' => $this->model->code,
            'rate' => $this->model->rate,
            'description' => $this->model->description,
            'applied_to_organization' => $this->model->applied_to_organization,
            'add_ons_count' => 0,
            'customers_count' => 0,
            'plans_count' => 0,
            'charges_count' => 0,
            'commitments_count' => 0,
            'created_at' => $this->serializeDatetime($this->model->created_at),
        ];
    }
}
