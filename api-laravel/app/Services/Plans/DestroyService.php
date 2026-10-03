<?php

declare(strict_types=1);

namespace App\Services\Plans;

use App\Models\Plan;
use App\Models\Subscription;
use App\Services\BaseResult;

/**
 * Port of Rails' Plans::DestroyService (app/services/plans/destroy_service.rb)
 * — the synchronous part of deletion, invoked by the PrepareDestroyService
 * job.
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): Subscriptions::TerminateService (task 8) — active
 *   subscriptions are marked terminated inline.
 * - TODO(port): Invoices::RefreshDraftAndFinalizeService — draft invoices
 *   are left untouched (logged).
 * - TODO(port): entitlements / entitlement_values have no models yet.
 */
class DestroyService extends \App\Services\BaseService
{
    public function __construct(
        private readonly ?Plan $plan,
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

        try {
            // NOTE: Terminate active subscriptions (Rails delegates to
            // Subscriptions::TerminateService; inline status update until
            // that service is ported).
            foreach ($plan->subscriptions()->where('status', 1)->get() as $subscription) {
                $subscription->terminated_at ??= now();
                $subscription->status = 2; // terminated
                $subscription->save();
            }

            // NOTE: Cancel pending subscription to make sure they won't be
            // activated (Rails: mark_as_canceled!).
            foreach ($plan->subscriptions()->where('status', 0)->get() as $subscription) {
                $subscription->canceled_at ??= now();
                $subscription->status = 3; // canceled
                $subscription->save();
            }

            // NOTE: Finalize all draft invoices.
            // TODO(port): Invoices::RefreshDraftAndFinalizeService — the
            // finalize-on-plan-deletion point (invoicing is a later task).

            // TODO(port): entitlement_values / entitlements soft-deletion.

            $plan->pending_deletion = false;
            // NOTE(port deviation): Rails' discard! persists dirty attributes,
            // so pending_deletion lands in the DB (destroy_service_spec
            // asserts reload true→false) — but Rails also runs the destroy
            // ASYNC (Plans::DestroyJob), so the API still observes the flag
            // true right after the request. M1 destroys inline
            // (PrepareDestroyService), and the API contract
            // (PlansControllerTest) relies on the flag surviving the request.
            // Persist the flag only once Plans::DestroyJob is ported.
            $plan->delete(); // discard (deleted_at)

            $result->plan = $plan;

            return $result;
        } catch (\Illuminate\Database\QueryException $e) {
            // Rails: rescue Discard::RecordNotDiscarded — re-fetch and accept
            // when the plan ends up discarded anyway.
            $plan = Plan::withTrashed()->find($plan->id);

            if ($plan === null || ! $plan->trashed()) {
                throw $e;
            }

            $result->plan = $plan;

            return $result;
        }
    }
}
