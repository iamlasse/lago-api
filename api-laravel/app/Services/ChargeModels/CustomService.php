<?php

declare(strict_types=1);

namespace App\Services\ChargeModels;

use App\Support\MoneyMath;

/**
 * Port of Rails' ChargeModels::CustomService.
 */
class CustomService extends AbstractChargeModel
{
    protected function computeAmount(): string
    {
        return (string) ($this->aggregationResult->customAggregation['amount'] ?? '0');
    }

    protected function computeProjectedAmount(): string
    {
        return $this->projectedAmountFromCurrentAmount();
    }

    protected function unitAmount(): string
    {
        $totalUnits = $this->unitAmountDenominator();

        return MoneyMath::compare($totalUnits, '0') === 0
            ? '0'
            : MoneyMath::fdiv((string) $this->result->amount, $totalUnits);
    }
}
