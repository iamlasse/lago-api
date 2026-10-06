<?php

declare(strict_types=1);

namespace App\Services\Subscriptions\Concerns;

use App\Models\Charge;
use App\Services\Charges\OverrideService;

/**
 * Port of Rails' Subscriptions::Concerns::ChargeOverrideConcern
 * (app/services/subscriptions/concerns/charge_override_concern.rb).
 *
 * The consuming service must expose `$this->charge`.
 */
trait ChargeOverrideConcern
{
    /**
     * The target plan's matching charge override, created from the catalog
     * (parent) charge when missing.
     */
    protected function findOrCreateChargeOverride(mixed $targetPlan): Charge
    {
        $parentCharge = $this->findParentCharge();

        $existingOverride = $targetPlan->charges()
            ->where('parent_id', $parentCharge->id)
            ->first();

        if ($existingOverride !== null) {
            return $existingOverride;
        }

        $overrideResult = OverrideService::callBang(
            charge: $parentCharge,
            params: ['plan' => $targetPlan],
        );

        return $overrideResult->charge;
    }

    /** The catalog charge behind the resolved charge (its parent, or itself). */
    protected function findParentCharge(): Charge
    {
        if ($this->charge->parent_id !== null) {
            return $this->charge->parent;
        }

        return $this->charge;
    }
}
