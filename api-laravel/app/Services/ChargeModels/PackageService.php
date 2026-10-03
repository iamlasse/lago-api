<?php

declare(strict_types=1);

namespace App\Services\ChargeModels;

use App\Support\MoneyMath;

/**
 * Port of Rails' ChargeModels::PackageService.
 */
class PackageService extends AbstractChargeModel
{
    protected function computeAmount(): string
    {
        if (MoneyMath::compare($this->paidUnits(), '0') < 0) {
            return '0';
        }

        // NOTE: Check how many packages (groups of units) are consumed.
        // It's rounded up, because a group counts from its first unit.
        $packageCount = MoneyMath::ceil(MoneyMath::fdiv($this->paidUnits(), (string) $this->perPackageSize()));

        return MoneyMath::mul((string) $packageCount, $this->perPackageUnitAmount());
    }

    protected function computeProjectedAmount(): string
    {
        $projectedUnits = (string) $this->result->projectedUnits;

        if (MoneyMath::compare($projectedUnits, '0') === 0) {
            return '0';
        }

        // Calculate projected paid units (after free units)
        $projPaidUnits = MoneyMath::sub($projectedUnits, (string) ($this->properties()['free_units'] ?? 0));
        if (MoneyMath::compare($projPaidUnits, '0') <= 0) {
            return '0';
        }

        // Calculate how many packages are needed for projected usage
        $projPackageCount = MoneyMath::ceil(MoneyMath::fdiv($projPaidUnits, (string) $this->perPackageSize()));

        return MoneyMath::mul((string) $projPackageCount, $this->perPackageUnitAmount());
    }

    protected function unitAmount(): string
    {
        if (MoneyMath::compare($this->paidUnits(), '0') <= 0) {
            return '0';
        }

        return MoneyMath::fdiv($this->computeAmount(), $this->paidUnits());
    }

    /** @return array<string, mixed> */
    protected function amountDetails(): array
    {
        $freeUnits = (string) ($this->properties()['free_units'] ?? 0);
        $units = $this->units();

        // Rails emits the raw property (a JSON number in the golden) — keep
        // the type through instead of string-casting.
        $packageSize = $this->properties()['package_size'] ?? 0;

        if (MoneyMath::compare($units, '0') === 0) {
            return ['free_units' => '0.0', 'paid_units' => '0.0', 'per_package_size' => 0, 'per_package_unit_amount' => '0.0'];
        }

        if (MoneyMath::compare($this->paidUnits(), '0') < 0) {
            return [
                'free_units' => $freeUnits,
                'paid_units' => '0.0',
                'per_package_size' => $packageSize,
                'per_package_unit_amount' => $this->perPackageUnitAmount(),
            ];
        }

        return [
            'free_units' => $freeUnits,
            'paid_units' => $this->paidUnits(),
            'per_package_size' => $packageSize,
            'per_package_unit_amount' => $this->perPackageUnitAmount(),
        ];
    }

    protected function paidUnits(): string
    {
        return MoneyMath::sub($this->units(), (string) ($this->properties()['free_units'] ?? 0));
    }

    protected function perPackageSize(): string
    {
        return (string) $this->properties()['package_size'];
    }

    protected function perPackageUnitAmount(): string
    {
        return (string) ($this->properties()['amount'] ?? '0');
    }
}
