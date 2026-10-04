<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Support\MoneyMath;
use App\Models\ContractRateCard;
use App\Serializers\Base\ModelSerializer;
use App\Serializers\V1\Concerns\FormatsDatetime;

/**
 * Port of Rails' V2::ContractAppliedRateCardSerializer
 * (app/serializers/v2/contract_applied_rate_card_serializer.rb).
 */
final class ContractAppliedRateCardSerializer extends ModelSerializer
{
    use FormatsDatetime;

    public function serialize(): array
    {
        /** @var ContractRateCard $contractRateCard */
        $contractRateCard = $this->model;

        return [
            'lago_id' => $contractRateCard->id,
            'external_contract_id' => $contractRateCard->contract->external_id,
            'rate_card_code' => $contractRateCard->rateCard->code,
            // Rails renders the BigDecimal units with to_s("F") at
            // JSON-encode time.
            'units' => $contractRateCard->units === null
                ? null
                : MoneyMath::toF((string) $contractRateCard->units),
            'effective_date' => $this->serializeDate($contractRateCard->effective_date),
            'billing_anchor_date' => $this->serializeDate($contractRateCard->billing_anchor_date),
            'next_billing_at' => $this->serializeDatetime($contractRateCard->next_billing_at),
            // count() reads the loaded collection when the caller preloaded it.
            'rate_phases_count' => $contractRateCard->ratePhases->count(),
            'created_at' => $this->serializeDatetime($contractRateCard->created_at),
            'updated_at' => $this->serializeDatetime($contractRateCard->updated_at),
        ];
    }
}
