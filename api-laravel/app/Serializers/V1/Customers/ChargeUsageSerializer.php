<?php

declare(strict_types=1);

namespace App\Serializers\V1\Customers;

use stdClass;
use App\Models\Fee;
use App\Support\MoneyMath;
use App\Serializers\Base\ModelSerializer;

/**
 * Port of Rails' V1::Customers::ChargeUsageSerializer
 * (app/serializers/v1/customers/charge_usage_serializer.rb) — groups the
 * current-usage fees per charge and rolls the usage up per group.
 *
 * NOTE: filters always serialize to [] — per-filter fees arrive with the
 * M2 filters pipeline (TODO(port)); grouped_usage and
 * presentation_breakdowns serialize their (empty) Rails shapes.
 */
class ChargeUsageSerializer extends ModelSerializer
{
    /** @param list<Fee> $fees */
    public function __construct(
        private readonly array $fees,
        array $options = [],
    ) {
        parent::__construct(new stdClass, $options);
    }

    /** @return list<array<string, mixed>> */
    public function serialize(): array
    {
        /** @var array<int|string, list<Fee>> $groups */
        $groups = [];

        foreach ($this->fees as $fee) {
            $groups[$fee->charge_id][] = $fee;
        }

        $payload = [];

        foreach ($groups as $fees) {
            $fee = $fees[0];

            $payload[] = [
                ...$this->usageData($fees),
                'charge' => $this->chargeData($fee),
                'billable_metric' => $this->billableMetricData($fee),
                'filters' => [],
                'grouped_usage' => $this->groupedUsage($fees),
                'presentation_breakdowns' => [],
            ];
        }

        return $payload;
    }

    /** @return list<Fee> */
    /** @return list<Fee> */
    protected function fees(): array
    {
        return array_values($this->fees);
    }

    /**
     * @param  list<Fee>  $fees
     * @return array<string, mixed>
     */
    protected function usageData(array $fees): array
    {
        return [
            'units' => $this->sumUnits($fees, 'units'),
            'total_aggregated_units' => $this->sumUnits($fees, 'total_aggregated_units'),
            'events_count' => array_sum(array_map(fn (Fee $fee) => (int) $fee->events_count, $fees)),
            'amount_cents' => array_sum(array_map(fn (Fee $fee) => (int) $fee->amount_cents, $fees)),
            'amount_currency' => $fees[0]->amount_currency,
        ];
    }

    /** @return array<string, mixed> */
    protected function chargeData(Fee $fee): array
    {
        return [
            'lago_id' => $fee->charge_id,
            'code' => $fee->charge->code,
            'charge_model' => $fee->charge->charge_model,
            'invoice_display_name' => $fee->charge->invoice_display_name,
        ];
    }

    /** @return array<string, mixed> */
    protected function billableMetricData(Fee $fee): array
    {
        $metric = $fee->charge->billableMetric;

        return [
            'lago_id' => $metric->id,
            'name' => $metric->name,
            'code' => $metric->code,
            'aggregation_type' => $metric->aggregation_type?->label(),
        ];
    }

    /**
     * @param  list<Fee>  $fees
     * @return list<array<string, mixed>>
     */
    protected function groupedUsage(array $fees): array
    {
        if (! collect($fees)->contains(fn (Fee $fee) => (array) $fee->grouped_by !== [])) {
            return [];
        }

        $groups = [];

        foreach ($fees as $fee) {
            $key = json_encode($fee->grouped_by ?? []);
            $groups[$key][] = $fee;
        }

        $payload = [];

        foreach ($groups as $groupedFees) {
            $payload[] = [
                ...collect($this->usageData($groupedFees))->except('amount_currency')->all(),
                'grouped_by' => $groupedFees[0]->grouped_by ?? [],
                'filters' => [],
                'presentation_breakdowns' => [],
            ];
        }

        return $payload;
    }

    /** @param list<Fee> $fees */
    private function sumUnits(array $fees, string $field): string
    {
        $total = '0';

        foreach ($fees as $fee) {
            $total = MoneyMath::add($total, (string) ($fee->{$field} ?? '0'));
        }

        // Rails: BigDecimal#to_s — fixed notation, one fractional digit
        // minimum ("15" -> "15.0").
        return MoneyMath::toF($total);
    }
}
