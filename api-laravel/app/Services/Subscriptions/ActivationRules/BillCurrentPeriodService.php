<?php

declare(strict_types=1);

namespace App\Services\Subscriptions\ActivationRules;

use App\Models\Subscription;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Jobs\BillSubscriptionJob;
use App\Models\InvoiceSubscription;
use App\Services\Subscriptions\DatesService;

/**
 * Port of Rails' Subscriptions::ActivationRules::BillCurrentPeriodService
 * (app/services/subscriptions/activation_rules/bill_current_period_service.rb)
 * — after an upgraded-out-of-gating subscription activates, bills the part
 * of the current period the gating window covered.
 */
class BillCurrentPeriodService extends BaseService
{
    protected ?\Carbon\CarbonInterface $billingAt = null;

    public function __construct(
        private readonly Subscription $subscription,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult();

        if (! $this->subscription->active()) {
            return $result;
        }

        if ($this->subscription->previousSubscription !== null) {
            return $result;
        }

        if (! $this->subscription->activationRules()->where('type', 'payment')->exists()) {
            return $result;
        }

        if ($this->billingAt()->lessThanOrEqualTo($this->subscription->started_at)) {
            return $result;
        }

        if ($this->alreadyBilled()) {
            return $result;
        }

        BillSubscriptionJob::dispatch(
            [$this->subscription],
            (int) $this->billingAt()->getTimestamp(),
            'subscription_periodic',
        );

        return $result;
    }

    // -- Helpers ----------------------------------------------------------------------

    protected function alreadyBilled(): bool
    {
        $boundaries = DatesService::newInstance($this->subscription, $this->billingAt(), false);

        return InvoiceSubscription::matching($this->subscription, $boundaries);
    }

    /**
     * Beginning of the period in progress, which is the boundary tick the
     * billing clock would have used to bill it. Yearly and semiannual plans
     * with monthly-billed charges or fixed charges are billed by the clock at
     * every monthly split boundary, so the split window applies instead.
     */
    protected function billingAt(): \Carbon\CarbonInterface
    {
        if ($this->billingAt !== null) {
            return $this->billingAt;
        }

        $dates = DatesService::newInstance($this->subscription, now(), true);

        if ($this->subscription->plan->chargesBilledInMonthlySplitIntervals()) {
            return $this->billingAt = $dates->chargesFromDatetime()
                ?? $dates->previousBeginningOfPeriod(true);
        }

        if ($this->subscription->plan->fixedChargesBilledInMonthlySplitIntervals()) {
            return $this->billingAt = $dates->fixedChargesFromDatetime()
                ?? $dates->previousBeginningOfPeriod(true);
        }

        return $this->billingAt = $dates->previousBeginningOfPeriod(true);
    }
}
