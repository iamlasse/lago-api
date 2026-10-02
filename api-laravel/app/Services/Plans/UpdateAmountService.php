<?php

declare(strict_types=1);

namespace App\Services\Plans;

use App\Models\Plan;
use App\Models\Subscription;
use App\Services\BaseResult;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' Plans::UpdateAmountService
 * (app/services/plans/update_amount_service.rb) — the optimistic child-plan
 * amount update enqueued by the parent's update (Rails: Plans::UpdateAmountJob).
 *
 * TODO(port): Subscriptions::PlanUpgradeService (task 8) — the pending-
 * subscription upgrade branch is marked inline.
 */
class UpdateAmountService extends \App\Services\BaseService
{
    public function __construct(
        private readonly ?Plan $plan,
        private readonly int $amountCents,
        private readonly int $expectedAmountCents,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('plan');

        if ($this->plan === null) {
            return $result->notFoundFailure('plan');
        }

        $plan = $this->plan;

        $result->plan = $plan;

        if ($plan->amount_cents !== $this->expectedAmountCents) {
            return $result;
        }

        $plan->amount_cents = $this->amountCents;

        try {
            DB::transaction(function () use ($plan): void {
                $errors = $plan->validateAttributes();

                if ($errors !== []) {
                    static::makeResult()->recordValidationFailure($errors)->raiseIfError();
                }

                $plan->save();

                $this->processPendingSubscriptions($plan);
            });

            return $result;
        } catch (\App\Services\Failures\FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }

    private function processPendingSubscriptions(Plan $plan): void
    {
        $pending = Subscription::query()
            ->where('plan_id', $plan->id)
            ->where('status', 0) // pending
            ->get();

        foreach ($pending as $subscription) {
            if ($subscription->previous_subscription_id === null) {
                continue;
            }

            $previousPlan = Plan::find(
                Subscription::find($subscription->previous_subscription_id)?->plan_id,
            );

            if ($previousPlan !== null
                && $plan->yearlyAmountCents() >= $previousPlan->yearlyAmountCents()) {
                // TODO(port): Subscriptions::PlanUpgradeService.call — the
                // upgrade emission point; the upgrade is skipped until that
                // service exists.
            }
        }
    }
}
