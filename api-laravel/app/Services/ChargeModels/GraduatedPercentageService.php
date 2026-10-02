<?php

declare(strict_types=1);

namespace App\Services\ChargeModels;

use App\Support\MoneyMath;

/**
 * Port of Rails' ChargeModels::GraduatedPercentageService.
 */
class GraduatedPercentageService extends AbstractChargeModel
{
    /** @return list<array<string, mixed>> */
    protected function ranges(): array
    {
        return array_values(array_map(
            fn ($range) => (array) $range,
            $this->properties()['graduated_percentage_ranges'] ?? [],
        ));
    }

    /** @return array<string, mixed> */
    protected function amountDetails(): array
    {
        $amounts = [];
        $units = $this->units();

        foreach ($this->ranges() as $range) {
            $detail = (new RangeGraduatedPercentageService(range: $range, totalUnits: $units))->call();

            // On the first pay-in-advance event: delta = cost(1 unit) - cost(0
            // units, exclude_event: true). Exclude the flat fee from cost(0).
            if (MoneyMath::compare($units, '0') === 0 && ($this->properties()['exclude_event'] ?? false)) {
                $detail['flat_unit_amount'] = '0';
                $detail['total_with_flat_amount'] = '0';
            }

            $amounts[] = $detail;

            if (($range['to_value'] ?? null) === null || MoneyMath::compare((string) $range['to_value'], $units) >= 0) {
                break;
            }
        }

        return ['graduated_percentage_ranges' => $amounts];
    }

    protected function computeAmount(): string
    {
        $total = '0';

        foreach ($this->amountDetails()['graduated_percentage_ranges'] as $detail) {
            $total = MoneyMath::add($total, (string) $detail['total_with_flat_amount']);
        }

        return $total;
    }

    protected function computeProjectedAmount(): string
    {
        return $this->projectedAmountFromCurrentAmount();
    }

    protected function unitAmount(): string
    {
        return $this->zeroIfNoTotalUnits();
    }
}
