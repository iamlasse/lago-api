<?php

declare(strict_types=1);

namespace App\Services\ChargeModels;

use App\Support\MoneyMath;

/**
 * Port of Rails' ChargeModels::AmountDetails::RangeGraduatedPercentageService.
 */
final class RangeGraduatedPercentageService
{
    public function __construct(
        private readonly array $range,
        private readonly string $totalUnits,
    ) {}

    /** @return array<string, mixed> */
    public function call(): array
    {
        return [
            'from_value' => $this->range['from_value'] ?? null,
            'to_value' => $this->range['to_value'] ?? null,
            'flat_unit_amount' => $this->flatUnitAmount(),
            'rate' => $this->rate(),
            'units' => $this->units(),
            'per_unit_total_amount' => $this->perUnitTotalAmount(),
            'total_with_flat_amount' => $this->totalWithFlatAmount(),
        ];
    }

    private function flatUnitAmount(): string
    {
        return (string) ($this->range['flat_amount'] ?? '0');
    }

    private function rate(): string
    {
        return (string) ($this->range['rate'] ?? '0');
    }

    private function perUnitTotalAmount(): string
    {
        return MoneyMath::fdiv(MoneyMath::mul($this->units(), $this->rate()), '100');
    }

    private function totalWithFlatAmount(): string
    {
        return MoneyMath::add($this->perUnitTotalAmount(), $this->flatUnitAmount());
    }

    /**
     * NOTE: compute how many units to bill in the range.
     */
    private function units(): string
    {
        $fromValue = (string) ($this->range['from_value'] ?? '0');
        $toValue = $this->range['to_value'] ?? null;

        // NOTE: total_units is higher than the to_value of the range
        if ($toValue !== null && MoneyMath::compare($this->totalUnits, (string) $toValue) >= 0) {
            $lowerBound = MoneyMath::compare($fromValue, '0') === 0 ? '1' : $fromValue;

            return MoneyMath::add(MoneyMath::sub((string) $toValue, $lowerBound), '1');
        }

        // NOTE: total_units is in the range
        if (MoneyMath::compare($fromValue, '0') === 0) {
            return $this->totalUnits;
        }

        return MoneyMath::add(MoneyMath::sub($this->totalUnits, $fromValue), '1');
    }
}
