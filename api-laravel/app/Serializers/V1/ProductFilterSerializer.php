<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Models\ProductFilter;
use App\Serializers\Base\ModelSerializer;
use App\Serializers\V1\Concerns\FormatsDatetime;

/**
 * Port of Rails' V2::ProductFilterSerializer
 * (app/serializers/v2/product_filter_serializer.rb). Root name "filter".
 */
final class ProductFilterSerializer extends ModelSerializer
{
    use FormatsDatetime;

    public function serialize(): array
    {
        /** @var ProductFilter $filter */
        $filter = $this->model;

        return [
            'lago_id' => $filter->id,
            'name' => $filter->name,
            'code' => $filter->code,
            'description' => $filter->description,
            'invoice_display_name' => $filter->invoice_display_name,
            'values' => $this->values(),
            'created_at' => $this->serializeDatetime($filter->created_at),
            'updated_at' => $this->serializeDatetime($filter->updated_at),
        ];
    }

    /**
     * options[:values] lets the destroy endpoint echo the values discarded
     * by the service.
     *
     * @return list<array{key: ?string, value: ?string}>
     */
    private function values(): array
    {
        /** @var iterable<object> $values */
        $values = $this->options['values'] ?? $this->model->values;

        $out = [];

        foreach ($values as $value) {
            $out[] = [
                'key' => $value->billableMetricFilter->key,
                'value' => $value->value,
            ];
        }

        return $out;
    }
}
