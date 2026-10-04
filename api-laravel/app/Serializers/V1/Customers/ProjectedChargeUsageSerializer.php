<?php

declare(strict_types=1);

namespace App\Serializers\V1\Customers;

use App\Models\Fee;
use App\Support\UsageProjections;
use App\Support\SubscriptionUsage;

/**
 * Port of Rails' V1::Customers::ProjectedChargeUsageSerializer
 * (app/serializers/v1/customers/projected_charge_usage_serializer.rb) —
 * the per-charge current usage plus its end-of-period projection.
 */
class ProjectedChargeUsageSerializer extends ChargeUsageSerializer
{
    /** @param list<Fee> $fees */
    public function __construct(
        array $fees,
        protected readonly ?SubscriptionUsage $usage = null,
        array $options = [],
    ) {
        parent::__construct($fees, $options);
    }

    /** @return list<array<string, mixed>> */
    public function serialize(): array
    {
        /** @var array<int|string, list<Fee>> $groups */
        $groups = [];

        foreach ($this->fees() as $fee) {
            $groups[$fee->charge_id][] = $fee;
        }

        $payload = [];

        foreach ($groups as $fees) {
            $fee = $fees[0];
            $projection = $this->projections()->forIterable($fees);

            $payload[] = [
                ...collect($this->usageData($fees))->except('total_aggregated_units')->all(),
                'projected_units' => (string) $projection->units,
                'projected_amount_cents' => $projection->amountCents,
                'charge' => $this->projectedChargeData($fee),
                'billable_metric' => $this->billableMetricData($fee),
                'filters' => [],
                'grouped_usage' => [],
                'presentation_breakdowns' => [],
                'projected_presentation_breakdowns' => [],
            ];
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function projectedChargeData(Fee $fee): array
    {
        return [
            'lago_id' => $fee->charge_id,
            'charge_model' => $fee->charge->charge_model,
            'invoice_display_name' => $fee->charge->invoice_display_name,
        ];
    }

    private function projections(): UsageProjections
    {
        return $this->usage?->projections ?? new UsageProjections;
    }
}
