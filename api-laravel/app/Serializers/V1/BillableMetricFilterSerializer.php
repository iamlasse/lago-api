<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Serializers\Base\ModelSerializer;

/**
 * Port of Rails' V1::BillableMetricFilterSerializer
 * (app/serializers/v1/billable_metric_filter_serializer.rb).
 */
class BillableMetricFilterSerializer extends ModelSerializer
{
    public function serialize(): array
    {
        $values = $this->model->values ?? [];

        sort($values);

        return [
            'key' => $this->model->key,
            'values' => array_values($values),
        ];
    }
}
