<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Models\UsageThreshold;
use App\Serializers\Base\ModelSerializer;

/**
 * Port of Rails' V1::UsageThresholdSerializer
 * (app/serializers/v1/usage_threshold_serializer.rb).
 */
class UsageThresholdSerializer extends ModelSerializer
{
    /** @return array<string, mixed> */
    public function serialize(): array
    {
        /** @var UsageThreshold $threshold */
        $threshold = $this->model;

        return [
            'lago_id' => $threshold->id,
            'threshold_display_name' => $threshold->threshold_display_name,
            'amount_cents' => $threshold->amount_cents,
            'recurring' => $threshold->recurring,
            'created_at' => $threshold->created_at->toIso8601String(),
            'updated_at' => $threshold->updated_at->toIso8601String(),
        ];
    }
}
