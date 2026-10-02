<?php

declare(strict_types=1);

namespace App\Services\ChargeModels;

use App\Support\MoneyMath;

/**
 * Port of Rails' ChargeModels::AmountDetails::RangeGraduatedService
 * (app/services/charge_models/amount_details/range_graduated_service.rb).
 */
final class RangeGraduatedService
{
    public function __construct(
        private readonly array $range,
        private readonly string $totalUnits,
        private readonly bool $adjacentModel = false,
    ) {}

    /** @return array<string, mixed> */
    public function call(): array
    {
        return [
            'from_value' => $this->range['from_value'] ?? null,
            'to_value' => $this->range['to_value'] ?? null,
            'flat_unit_amount' => $this->flatUnitAmount(),
            'per_unit_amount' => $this->perUnitAmount(),
            'units' => $this->units(),
            'per_unit_total_amount' => $this->perUnitTotalAmount(),
            'total_with_flat_amount' => $this->totalWithFlatAmount(),
        ];
    }

    private function flatUnitAmount(): string
    {
        return (string) ($this->range['flat_amount'] ?? '0');
    }

    private function perUnitAmount(): string
    {
        return $this->units() === '0' || MoneyMath::compare($this->units(), '0') === 0
            ? '0'
            : (string) ($this->range['per_unit_amount'] ?? '0');
    }

    private function perUnitTotalAmount(): string
    {
        return MoneyMath::mul($this->units(), $this->perUnitAmount());
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
        $toValue = $this->range['to_value'] ?? null;

        $effectiveTotal = ($toValue !== null && MoneyMath::compare($this->totalUnits, (string) $toValue) >= 0)
            ? (string) $toValue
            : $this->totalUnits;

        if (MoneyMath::compare((string) ($this->range['from_value'] ?? '0'), '0') === 0) {
            return $effectiveTotal;
        }

        $diff = MoneyMath::sub($effectiveTotal, (string) ($this->range['from_value'] ?? '0'));

        return $this->adjacentModel ? $diff : MoneyMath::add($diff, '1');
    }
}
