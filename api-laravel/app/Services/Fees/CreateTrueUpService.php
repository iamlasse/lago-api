<?php

declare(strict_types=1);

namespace App\Services\Fees;

use App\Models\Fee;
use App\Support\Currency;
use App\Support\MoneyMath;
use App\Services\BaseResult;
use App\Support\Utils\Datetime;
use App\Models\BillingPeriodBoundaries;

/**
 * Port of Rails' Fees::CreateTrueUpService
 * (app/services/fees/create_true_up_service.rb) — when a minimum commitment
 * (min_amount_cents on the charge) was not reached, bills the missing
 * amount as a duplicated true-up fee.
 *
 * TODO(port): pricing_unit_usage (pricing units are not ported).
 */
class CreateTrueUpService extends \App\Services\BaseService
{
    public function __construct(
        private readonly ?Fee $fee,
        private readonly int $usedAmountCents,
        private readonly string $usedPreciseAmountCents,
    ) {}

    public function execute(): BaseResult
    {
        $result = BaseResult::of('true_up_fee');

        if ($this->fee === null) {
            return $result;
        }

        $proratedMin = $this->proratedMinAmountCents();

        if ($this->usedAmountCents >= $proratedMin) {
            return $result;
        }

        $amountCents = MoneyMath::round((string) ($proratedMin - $this->usedAmountCents));
        $preciseAmountCents = MoneyMath::sub((string) $proratedMin, $this->usedPreciseAmountCents);

        $trueUpFee = $this->fee->replicate();

        $trueUpFee->amount_cents = $amountCents;
        $trueUpFee->precise_amount_cents = $preciseAmountCents;
        $trueUpFee->units = '1';
        $trueUpFee->total_aggregated_units = '1';
        $trueUpFee->events_count = 0;
        $trueUpFee->charge_filter_id = null;
        $trueUpFee->true_up_parent_fee_id = $this->fee->id;
        $trueUpFee->unit_amount_cents = $amountCents;
        $trueUpFee->precise_unit_amount = MoneyMath::fdiv(
            $preciseAmountCents,
            (string) Currency::subunitToUnit((string) $this->fee->amount_currency),
        );

        $result->true_up_fee = $trueUpFee;

        return $result;
    }

    private function proratedMinAmountCents(): string
    {
        $boundaries = BillingPeriodBoundaries::fromFee($this->fee);

        $numberOfDaysToBill = Datetime::dateDiffWithTimezone(
            $boundaries->chargesFromDatetime,
            $boundaries->chargesToDatetime,
            $this->fee->subscription->customer->applicableTimezone(),
        );

        return MoneyMath::fdiv(
            MoneyMath::mul((string) $this->fee->charge->min_amount_cents, (string) $numberOfDaysToBill),
            (string) $boundaries->chargesDuration,
        );
    }
}
