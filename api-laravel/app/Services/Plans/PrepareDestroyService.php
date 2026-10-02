<?php

declare(strict_types=1);

namespace App\Services\Plans;

use App\Models\Plan;
use App\Services\BaseResult;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' Plans::PrepareDestroyService
 * (app/services/plans/prepare_destroy_service.rb) — flags the plan (and its
 * children) pending_deletion and schedules the actual destroy.
 *
 * TODO(port): Plans::DestroyJob does not exist yet — the destroy runs
 * inline (synchronously) after flagging; webhook emission point marked.
 */
class PrepareDestroyService extends \App\Services\BaseService
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

        DB::transaction(function () use ($plan): void {
            $plan->pending_deletion = true;
            $plan->save();

            Plan::query()->where('parent_id', $plan->id)->update(['pending_deletion' => true]);

            // TODO(port): Plans::DestroyJob.perform_later(plan) — the job
            // queue is a later milestone; destroying inline keeps M1 semantics.
            DestroyService::call(plan: $plan)->raiseIfError();
        });

        // TODO(port): SendWebhookJob.perform_after_commit("plan.deleted", plan)
        // — webhook emission hook point.

        $result->plan = $plan->refresh();

        return $result;
    }
}
