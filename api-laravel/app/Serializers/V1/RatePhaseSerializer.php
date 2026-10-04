<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Models\RatePhase;
use App\Serializers\Base\ModelSerializer;

/**
 * Port of Rails' V2::RatePhaseSerializer
 * (app/serializers/v2/rate_phase_serializer.rb).
 */
final class RatePhaseSerializer extends ModelSerializer
{
    public function serialize(): array
    {
        /** @var RatePhase $ratePhase */
        $ratePhase = $this->model;

        return [
            'lago_id' => $ratePhase->id,
            'code' => $ratePhase->code,
            'position' => (int) $ratePhase->position,
            'name' => $ratePhase->name,
            'billing_interval_cycle_count' => $ratePhase->billing_interval_cycle_count === null
                ? null
                : (int) $ratePhase->billing_interval_cycle_count,
            'rate_override' => $this->rateOverride(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function rateOverride(): ?array
    {
        /** @var RatePhase $ratePhase */
        $ratePhase = $this->model;

        if ($ratePhase->rateOverride === null) {
            return null;
        }

        return (new RateOverrideSerializer($ratePhase->rateOverride))->serialize();
    }
}
