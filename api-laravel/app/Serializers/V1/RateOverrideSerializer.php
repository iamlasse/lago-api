<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Models\RateOverride;
use App\Serializers\Base\ModelSerializer;

/**
 * Port of Rails' V2::RateOverrideSerializer
 * (app/serializers/v2/rate_override_serializer.rb).
 */
final class RateOverrideSerializer extends ModelSerializer
{
    public function serialize(): array
    {
        /** @var RateOverride $rateOverride */
        $rateOverride = $this->model;

        $properties = $rateOverride->properties();

        return [
            'lago_id' => $rateOverride->id,
            'rate_model' => $rateOverride->getRawOriginal('rate_model'),
            'rate_properties' => $properties === [] ? null : $properties,
            'min_amount_cents' => (int) $rateOverride->min_amount_cents,
            'billing_interval_count' => $rateOverride->billing_interval_count === null
                ? null
                : (int) $rateOverride->billing_interval_count,
            'billing_interval_unit' => $rateOverride->getRawOriginal('billing_interval_unit'),
            'pricing_unit_conversion_rate' => $rateOverride->pricing_unit_conversion_rate,
        ];
    }
}
