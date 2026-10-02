<?php

declare(strict_types=1);

namespace App\Services\ChargeModels;

use App\Support\MoneyMath;

/**
 * Port of Rails' ChargeModels::VolumeService.
 */
class VolumeService extends AbstractChargeModel
{
    /** @return list<array<string, mixed>> ranges sorted by from_value. */
    protected function ranges(): array
    {
        $ranges = array_map(
            fn ($range) => (array) $range,
            $this->properties()['volume_ranges'] ?? [],
        );

        usort($ranges, fn (array $a, array $b) => MoneyMath::compare(
            (string) ($a['from_value'] ?? '0'),
            (string) ($b['from_value'] ?? '0'),
        ));

        return $ranges;
    }

    protected function computeAmount(): string
    {
        return MoneyMath::add($this->perUnitTotalAmount(), $this->flatUnitAmount());
    }

    protected function computeProjectedAmount(): string
    {
        $projectedUnits = (string) $this->result->projectedUnits;

        if (MoneyMath::compare($projectedUnits, '0') === 0) {
            return '0';
        }

        $projectedCeil = (string) MoneyMath::ceil($projectedUnits);

        foreach ($this->ranges() as $range) {
            $fromValue = (string) ($range['from_value'] ?? '0');
            $toValue = $range['to_value'] ?? null;

            $matches = MoneyMath::compare($fromValue, $projectedCeil) <= 0
                && ($toValue === null || MoneyMath::compare($projectedUnits, (string) $toValue) <= 0);

            if (! $matches) {
                continue;
            }

            $perUnitPrice = (string) ($range['per_unit_amount'] ?? '0');
            $flatFee = (string) ($range['flat_amount'] ?? '0');

            return MoneyMath::add(MoneyMath::mul($projectedUnits, $perUnitPrice), $flatFee);
        }

        return '0';
    }

    protected function unitAmount(): string
    {
        if (MoneyMath::compare($this->numberOfUnits(), '0') === 0) {
            return '0';
        }

        return MoneyMath::fdiv($this->computeAmount(), $this->numberOfUnits());
    }

    /** @return array<string, mixed> */
    protected function amountDetails(): array
    {
        return [
            'flat_unit_amount' => $this->flatUnitAmount(),
            'per_unit_amount' => MoneyMath::compare($this->numberOfUnits(), '0') === 0
                ? '0'
                : $this->perUnitAmount(),
            'per_unit_total_amount' => $this->perUnitTotalAmount(),
        ];
    }

    protected function flatUnitAmount(): string
    {
        return (string) ($this->matchingRange()['flat_amount'] ?? '0');
    }

    protected function perUnitAmount(): string
    {
        return MoneyMath::fdiv($this->perUnitTotalAmount(), $this->numberOfUnits());
    }

    protected function perUnitTotalAmount(): string
    {
        return MoneyMath::mul($this->units(), (string) ($this->matchingRange()['per_unit_amount'] ?? '0'));
    }

    /** @return array<string, mixed>|null */
    protected function matchingRange(): ?array
    {
        $units = $this->numberOfUnits();
        $unitsCeil = (string) MoneyMath::ceil($units);

        foreach ($this->ranges() as $range) {
            $fromValue = (string) ($range['from_value'] ?? '0');
            $toValue = $range['to_value'] ?? null;

            $matches = MoneyMath::compare($fromValue, $unitsCeil) <= 0
                && ($toValue === null || MoneyMath::compare($units, (string) $toValue) <= 0);

            if ($matches) {
                return $range;
            }
        }

        return null;
    }

    /**
     * NOTE: prorated charges are priced on the full units number (the whole
     * period's units set the tier), non-prorated on the aggregated units.
     */
    protected function numberOfUnits(): string
    {
        if ($this->pricingStructure->prorated && $this->result->fullUnitsNumber !== null) {
            return $this->result->fullUnitsNumber;
        }

        return $this->units();
    }
}
