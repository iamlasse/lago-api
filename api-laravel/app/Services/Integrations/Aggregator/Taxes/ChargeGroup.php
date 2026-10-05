<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\Taxes;

use App\Models\Fee;
use App\Services\Integrations\Aggregator\Taxes\Invoices\ChargeFeeGroup;
use SplObjectStorage;

/**
 * Port of Rails' Integrations::Aggregator::Taxes::ChargeGroup — the grouping
 * helper behind ChargeFeeGroup.build: consecutive-independent group_by of
 * fees by their charge (nil charge → the fee object identity, i.e. alone).
 */
final class ChargeGroup
{
    /**
     * Rails: `.by_charge(records) { |fee| fee.charge_id if fee.charge? }` —
     * group_by preserving first-seen order; singletons stay bare.
     *
     * @param  list<Fee>  $records
     * @return list<Fee|ChargeFeeGroup>
     */
    public static function byCharge(array $records): array
    {
        $groups = [];
        $order = [];
        $objectIds = new SplObjectStorage();

        foreach ($records as $fee) {
            $key = $fee->isCharge() ? $fee->charge_id : 'object:'.spl_object_id($fee);

            $groups[$key] ??= [];
            $groups[$key][] = $fee;
            $order[$key] ??= count($order);
        }

        asort($order);

        $result = [];
        foreach (array_keys($order) as $key) {
            $chargeFees = $groups[$key];
            $result[] = count($chargeFees) === 1 ? $chargeFees[0] : new ChargeFeeGroup(fees: $chargeFees);
        }

        return $result;
    }
}
