<?php

declare(strict_types=1);

namespace App\Services\Charges\Validators;

use App\Services\Validators\DecimalAmount;

/**
 * Port of Rails' Charges::Validators::PackageService.
 */
class PackageService extends BaseService
{
    public function valid(): bool
    {
        $this->validateAmount();
        $this->validateFreeUnits();
        $this->validatePackageSize();

        return parent::valid();
    }

    private function amount(): mixed
    {
        return $this->properties['amount'] ?? null;
    }

    private function validateAmount(): void
    {
        if (! DecimalAmount::validAmount($this->amount())) {
            $this->addError('amount', 'invalid_amount');
        }
    }

    private function packageSize(): mixed
    {
        return $this->properties['package_size'] ?? null;
    }

    private function validatePackageSize(): void
    {
        $packageSize = $this->packageSize();

        if ($packageSize !== null && $packageSize !== '' && is_int($packageSize) && $packageSize > 0) {
            return;
        }

        $this->addError('package_size', 'invalid_package_size');
    }

    private function freeUnits(): mixed
    {
        return $this->properties['free_units'] ?? null;
    }

    private function validateFreeUnits(): void
    {
        $freeUnits = $this->freeUnits();

        if ($freeUnits !== null && $freeUnits !== '' && is_int($freeUnits) && $freeUnits >= 0) {
            return;
        }

        $this->addError('free_units', 'invalid_free_units');
    }
}
