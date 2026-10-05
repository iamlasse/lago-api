<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Serializers\Base\ModelSerializer;

/**
 * Port of Rails' V1::ApplicableUsageThresholdSerializer
 * (app/serializers/v1/applicable_usage_threshold_serializer.rb).
 */
class ApplicableUsageThresholdSerializer extends ModelSerializer
{
    /** @return array<string, mixed> */
    public function serialize(): array
    {
        return [
            'threshold_display_name' => $this->model->threshold_display_name,
            'amount_cents' => $this->model->amount_cents,
            'recurring' => $this->model->recurring,
        ];
    }
}
