<?php

declare(strict_types=1);

namespace App\Services\UsageThresholds;

use App\Models\Plan;
use App\Services\BaseResult;
use App\Models\UsageThreshold;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' UsageThresholds::OverrideService
 * (app/services/usage_thresholds/override_service.rb) — seeds the thresholds
 * of a freshly created override plan from the override params.
 */
class OverrideService extends \App\Services\BaseService
{
    public function __construct(
        private readonly array $usageThresholdsParams,
        private readonly Plan $newPlan,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('usage_thresholds');

        try {
            DB::transaction(function (): void {
                foreach ($this->usageThresholdsParams as $params) {
                    $params = (array) $params;

                    $usageThreshold = new UsageThreshold([
                        'organization_id' => $this->newPlan->organization_id,
                        'threshold_display_name' => $params['threshold_display_name'] ?? null,
                        'amount_cents' => (int) ($params['amount_cents'] ?? 0),
                        'recurring' => (bool) ($params['recurring'] ?? false),
                    ]);

                    $usageThreshold->plan_id = $this->newPlan->id;
                    $usageThreshold->save();
                }
            });
        } catch (\App\Services\Failures\FailedResult $e) {
            return $result->failWithError($e);
        }

        $result->usage_thresholds = $this->newPlan->usageThresholds()->get();

        return $result;
    }
}
