<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\Taxes\Invoices\Payloads;

use App\Models\Fee;
use App\Services\Integrations\Aggregator\Taxes\Invoices\ChargeFeeGroup;
use App\Services\Integrations\Aggregator\BasePayload as AggregatorBasePayload;

/**
 * Port of Rails' Integrations::Aggregator::Taxes::BasePayload — the fee-kind
 * → item-code dispatch over the aggregator BasePayload lookups.
 */
abstract class BasePayload extends AggregatorBasePayload
{
    /** Rails: `mapped_item(fee)`. */
    public function mappedItem(Fee|ChargeFeeGroup $fee): ?object
    {
        if ($fee instanceof ChargeFeeGroup) {
            $fee = $fee->fees[0];
        }

        if ($fee->isCharge()) {
            return $this->billable_metric_item($fee);
        }

        if ($fee->add_on_id !== null) {
            return $this->add_on_item($fee);
        }

        if ($fee->typeEnum()?->name === 'FixedCharge') {
            return $this->fixed_charge_item($fee);
        }

        if ($fee->typeEnum()?->name === 'Commitment') {
            return $this->commitment_item();
        }

        if ($fee->typeEnum()?->name === 'Subscription') {
            return $this->subscription_item();
        }

        return null;
    }
}
