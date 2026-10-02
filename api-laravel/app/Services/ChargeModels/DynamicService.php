<?php

declare(strict_types=1);

namespace App\Services\ChargeModels;

use App\Support\Currency;
use App\Support\MoneyMath;

/**
 * Port of Rails' ChargeModels::DynamicService — units are priced per-event
 * by the frontend; the aggregation carries the precise total in cents.
 */
class DynamicService extends AbstractChargeModel
{
    protected function computeAmount(): string
    {
        $totalUnits = $this->unitAmountDenominator();

        if (MoneyMath::compare($totalUnits, '0') === 0) {
            return '0';
        }

        $amountCents = $this->aggregationResult->preciseTotalAmountCents ?? '0';

        return MoneyMath::fdiv($amountCents, (string) Currency::subunitToUnit($this->pricingStructure->currency));
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
            : MoneyMath::fdiv($this->computeAmount(), $totalUnits);
    }
}
