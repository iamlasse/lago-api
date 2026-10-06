<?php

declare(strict_types=1);

namespace App\Services\Subscriptions\Concerns;

use App\Models\SubscriptionFixedChargeUnitsOverride;

/**
 * Ports of Rails' Subscriptions::Concerns::
 * FixedChargeUnitsOverrideDetectionConcern and
 * FixedChargeUnitsOverridePromotionConcern
 * (app/services/subscriptions/concerns/fixed_charge_units_override_*.rb).
 *
 * The consuming service must expose `$this->subscription`.
 */
trait FixedChargeUnitsOverrideConcern
{
    private const PLAN_OVERRIDES_FIXED_CHARGE_ALLOWED_KEYS = ['id', 'units', 'apply_units_immediately'];

    private const DEDICATED_ENDPOINT_ALLOWED_KEYS = ['units', 'apply_units_immediately'];

    /**
     * Rails: units_only_fixed_charge_params? — the dedicated GraphQL
     * endpoint's params carry units (and optionally apply_units_immediately)
     * and nothing else.
     */
    protected function unitsOnlyFixedChargeParams(array $params): bool
    {
        if (! array_key_exists('units', $params)) {
            return false;
        }

        return array_diff(array_keys($params), self::DEDICATED_ENDPOINT_ALLOWED_KEYS) === [];
    }

    /**
     * Rails: promote_units_overrides_to_fixed_charges_params — seed the
     * override plan with the customer's existing units override rows so they
     * survive the clone, then discard the rows.
     */
    protected function promoteUnitsOverridesToFixedChargesParams(array $existingParams = []): array
    {
        $overrides = SubscriptionFixedChargeUnitsOverride::query()
            ->where('subscription_id', $this->subscription->id)
            ->get();

        if ($overrides->isEmpty()) {
            return $existingParams;
        }

        $paramsById = [];

        foreach ($existingParams as $entry) {
            $entry = (array) $entry;
            if (($entry['id'] ?? null) !== null) {
                $paramsById[$entry['id']] = $entry;
            }
        }

        foreach ($overrides as $override) {
            $paramsById[$override->fixed_charge_id] ??= [
                'id' => $override->fixed_charge_id,
                'units' => $override->units,
            ];
        }

        foreach ($overrides as $override) {
            $override->delete();
        }

        return array_values($paramsById);
    }
}
