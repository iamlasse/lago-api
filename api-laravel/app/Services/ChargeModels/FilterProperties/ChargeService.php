<?php

declare(strict_types=1);

namespace App\Services\ChargeModels\FilterProperties;

use App\Services\Charges\AggregationChecks;

/**
 * Port of Rails' ChargeModels::FilterProperties::ChargeService.
 */
class ChargeService extends BaseService
{
    protected function baseAttributes(): array
    {
        $billableMetric = $this->chargeable->billableMetric;

        return AggregationChecks::isCustom($billableMetric) ? ['custom_properties'] : [];
    }

    /**
     * @return list<string>
     */
    protected function chargeModelAttributes(): array
    {
        $attributes = parent::chargeModelAttributes();

        return match ($this->chargeModel()) {
            'graduated_percentage' => array_merge($attributes, ['graduated_percentage_ranges']),
            'package' => array_merge($attributes, ['amount', 'free_units', 'package_size']),
            'percentage' => array_merge($attributes, [
                'fixed_amount',
                'free_units_per_events',
                'free_units_per_total_aggregation',
                'per_transaction_max_amount',
                'per_transaction_min_amount',
                'rate',
            ]),
            default => $attributes,
        };
    }
}
