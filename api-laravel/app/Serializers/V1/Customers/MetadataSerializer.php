<?php

declare(strict_types=1);

namespace App\Serializers\V1\Customers;

use App\Serializers\Base\ModelSerializer;
use App\Serializers\V1\Concerns\FormatsDatetime;

/**
 * Port of Rails' V1::Customers::MetadataSerializer.
 */
class MetadataSerializer extends ModelSerializer
{
    use FormatsDatetime;

    public function serialize(): array
    {
        return [
            'lago_id' => $this->model->id,
            'key' => $this->model->key,
            'value' => $this->model->value,
            'display_in_invoice' => $this->model->display_in_invoice,
            'created_at' => $this->serializeDatetime($this->model->created_at),
        ];
    }
}
