<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Models\PlanRateCard;
use App\Serializers\Base\ModelSerializer;
use App\Serializers\V1\Concerns\FormatsDatetime;

/**
 * Port of Rails' V2::PlanRateCardSerializer
 * (app/serializers/v2/plan_rate_card_serializer.rb).
 */
final class PlanRateCardSerializer extends ModelSerializer
{
    use FormatsDatetime;

    public function serialize(): array
    {
        /** @var PlanRateCard $planRateCard */
        $planRateCard = $this->model;

        return [
            'lago_id' => $planRateCard->id,
            'plan_code' => $planRateCard->catalogPlan->code,
            'rate_card_code' => $planRateCard->rateCard->code,
            'units' => $planRateCard->units,
            // Preloaded by the index so the count reads the loaded relation.
            'rate_phases_count' => $planRateCard->ratePhases->count(),
            'created_at' => $this->serializeDatetime($planRateCard->created_at),
            'updated_at' => $this->serializeDatetime($planRateCard->updated_at),
        ];
    }
}
