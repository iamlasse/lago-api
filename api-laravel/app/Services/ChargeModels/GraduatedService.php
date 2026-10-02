<?php

declare(strict_types=1);

namespace App\Services\ChargeModels;

use App\Support\MoneyMath;

/**
 * Port of Rails' ChargeModels::GraduatedService.
 *
 * TODO(port): prorated graduated charges (ProratedGraduatedService) need
 * per-event aggregation (M2); until then prorated graduated charges fall
 * back to this non-prorated model, matching Rails' forecasting fallback.
 */
class GraduatedService extends AbstractChargeModel
{
    /** @return list<array<string, mixed>> */
    protected function ranges(): array
    {
        return array_values(array_map(
            fn ($range) => (array) $range,
            $this->properties()['graduated_ranges'] ?? [],
        ));
    }

    /** @return array<string, mixed> */
    protected function amountDetails(): array
    {
        $amounts = [];
        $units = $this->units();

        foreach ($this->ranges() as $range) {
            $amounts[] = (new RangeGraduatedService(
                range: $range,
                totalUnits: $units,
                adjacentModel: $this->adjacentRanges(),
            ))->call();

            if (($range['to_value'] ?? null) === null || MoneyMath::compare((string) $range['to_value'], $units) >= 0) {
                break;
            }
        }

        return ['graduated_ranges' => $amounts];
    }

    protected function adjacentRanges(): bool
    {
        $ranges = $this->ranges();

        if (count($ranges) < 2) {
            return false;
        }

        for ($i = 1; $i < count($ranges); $i++) {
            $prev = $ranges[$i - 1];
            $curr = $ranges[$i];

            if (MoneyMath::compare((string) ($curr['from_value'] ?? '0'), (string) ($prev['to_value'] ?? '0')) !== 0) {
                return false;
            }
        }

        return true;
    }

    protected function computeAmount(): string
    {
        // On the first pay-in-advance event: delta = cost(1 unit) - cost(0 units,
        // exclude_event: true). Here we exclude the flat fee from cost(0 units).
        if (MoneyMath::compare($this->units(), '0') === 0 && ($this->properties()['exclude_event'] ?? false)) {
            return '0';
        }

        $total = '0';

        foreach ($this->amountDetails()['graduated_ranges'] as $detail) {
            $total = MoneyMath::add($total, (string) $detail['total_with_flat_amount']);
        }

        return $total;
    }

    protected function computeProjectedAmount(): string
    {
        $projectedUnits = (string) $this->result->projectedUnits;

        if (MoneyMath::compare($projectedUnits, '0') === 0) {
            return '0';
        }

        $remainingUnitsToPrice = $projectedUnits;
        $totalAmount = '0';
        $pricedUnitsCount = '0';

        foreach ($this->ranges() as $range) {
            $toValue = $range['to_value'] ?? null;
            $rangeTo = $toValue !== null ? (string) $toValue : null;

            $tierCapacity = $rangeTo === null
                ? null
                : MoneyMath::sub($rangeTo, $pricedUnitsCount);

            $unitsInThisTier = $tierCapacity === null
                ? $remainingUnitsToPrice
                : (MoneyMath::compare($remainingUnitsToPrice, $tierCapacity) < 0 ? $remainingUnitsToPrice : $tierCapacity);

            if (MoneyMath::compare($unitsInThisTier, '0') > 0) {
                $rangePerUnit = (string) ($range['per_unit_amount'] ?? '0');
                $rangeFlatAmount = (string) ($range['flat_amount'] ?? '0');
                $rangeAmount = MoneyMath::add(MoneyMath::mul($unitsInThisTier, $rangePerUnit), $rangeFlatAmount);

                $totalAmount = MoneyMath::add($totalAmount, $rangeAmount);
                $remainingUnitsToPrice = MoneyMath::sub($remainingUnitsToPrice, $unitsInThisTier);
                $pricedUnitsCount = MoneyMath::add($pricedUnitsCount, $unitsInThisTier);
            }

            if (MoneyMath::compare($remainingUnitsToPrice, '0') <= 0) {
                break;
            }
        }

        return $totalAmount;
    }

    protected function unitAmount(): string
    {
        return $this->zeroIfNoTotalUnits();
    }
}
