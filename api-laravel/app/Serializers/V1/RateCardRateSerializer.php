<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Models\RateCardRate;
use App\Serializers\Base\ModelSerializer;
use App\Serializers\V1\Concerns\FormatsDatetime;

/**
 * Port of Rails' V2::RateCardRateSerializer
 * (app/serializers/v2/rate_card_rate_serializer.rb).
 */
final class RateCardRateSerializer extends ModelSerializer
{
    use FormatsDatetime;

    public function serialize(): array
    {
        /** @var RateCardRate $rate */
        $rate = $this->model;

        return [
            'lago_id' => $rate->id,
            'code' => $rate->code,
            'effective_from' => $this->serializeDatetime($rate->effective_from),
            'status' => $rate->status(),
            'rate_model' => $rate->getRawOriginal('rate_model'),
            'rate_properties' => $this->presentProperties(),
            'min_amount_cents' => (int) $rate->min_amount_cents,
            'billing_interval_count' => (int) $rate->billing_interval_count,
            'billing_interval_unit' => $rate->getRawOriginal('billing_interval_unit'),
            'applied_pricing_unit_conversion_rate' => $rate->applied_pricing_unit_conversion_rate,
            'created_at' => $this->serializeDatetime($rate->created_at),
            'updated_at' => $this->serializeDatetime($rate->updated_at),
        ];
    }

    /**
     * Rails: RateProperties.present — the properties hash, null when empty.
     *
     * @return array<string, mixed>|null
     */
    private function presentProperties(): ?array
    {
        /** @var RateCardRate $rate */
        $rate = $this->model;

        $properties = $rate->properties();

        return $properties === [] ? null : $properties;
    }
}
