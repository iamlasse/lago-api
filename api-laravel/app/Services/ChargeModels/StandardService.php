<?php

declare(strict_types=1);

namespace App\Services\ChargeModels;

use App\Support\MoneyMath;

/**
 * Port of Rails' ChargeModels::StandardService.
 */
class StandardService extends AbstractChargeModel
{
    protected function computeAmount(): string
    {
        return MoneyMath::mul($this->units(), (string) ($this->properties()['amount'] ?? '0'));
    }

    protected function computeProjectedAmount(): string
    {
        return MoneyMath::mul((string) $this->result->projectedUnits, (string) ($this->properties()['amount'] ?? '0'));
    }

    protected function unitAmount(): string
    {
        $totalUnits = $this->unitAmountDenominator();

        return MoneyMath::compare($totalUnits, '0') === 0
            ? '0'
            : MoneyMath::fdiv($this->computeAmount(), $totalUnits);
    }
}
