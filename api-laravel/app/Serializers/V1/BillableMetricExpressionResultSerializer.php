<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Serializers\Base\ModelSerializer;

/**
 * Port of Rails' V1::BillableMetricExpressionResultSerializer
 * (app/serializers/v1/billable_metric_expression_result_serializer.rb) —
 * the model is the evaluate_expression result itself.
 */
class BillableMetricExpressionResultSerializer extends ModelSerializer
{
    public function serialize(): array
    {
        return [
            'value' => $this->model->evaluation_result,
        ];
    }
}
