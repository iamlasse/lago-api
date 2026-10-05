<?php

declare(strict_types=1);

namespace App\Services\UsageThresholds;

use App\Models\Plan;
use App\Models\Subscription;
use App\Services\BaseResult;
use App\Models\UsageThreshold;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' UsageThresholds::UpdateService
 * (app/services/usage_thresholds/update_service.rb) — the nested
 * usage_thresholds write of plans and subscriptions. `partial` marks the
 * PATCH form (only update what was sent); the full form replaces the model's
 * thresholds.
 *
 * Rails' model validations (amount_cents > 0, uniqueness of
 * [amount_cents, recurring] and of the single recurring threshold per plan)
 * are enforced here before insert, where the writes actually happen; the DB
 * CHECK backstops the one-of-plan-or-subscription rule.
 */
class UpdateService extends \App\Services\BaseService
{
    private ?\Illuminate\Support\Collection $thresholdsMemo = null;

    public function __construct(
        private readonly Plan|Subscription $model,
        private readonly array $usageThresholdsParams,
        private readonly bool $partial,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of();

        $params = $this->sanitizeParams($this->usageThresholdsParams);

        if ($params === [] && $this->partial) {
            return $result;
        }

        if ($this->missingAmountCents($params)) {
            return $result->singleValidationFailure('missing_amount_cents', 'usage_thresholds');
        }

        if ($this->duplicatedAmountCents($params)) {
            return $result->singleValidationFailure('duplicated_values', 'usage_thresholds');
        }

        if ($this->multipleRecurringThresholds($params)) {
            return $result->singleValidationFailure('multiple_recurring_thresholds', 'usage_thresholds');
        }

        try {
            DB::transaction(function () use ($params): void {
                if (! $this->partial) {
                    $this->deleteAllThresholds();
                }

                $this->updateRecurringThreshold($params);
                $this->updateOrCreateThresholds($params);
            });
        } catch (\App\Services\Failures\FailedResult $e) {
            return $result->failWithError($e);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            return $result->singleValidationFailure('duplicated_values', 'usage_thresholds');
        }

        return $result;
    }

    /**
     * Rails: #sanitize_params — only the three writable keys survive;
     * recurring defaults to false.
     *
     * @param  list<array<string, mixed>>  $params
     * @return list<array<string, mixed>>
     */
    private function sanitizeParams(array $params): array
    {
        return array_map(function (mixed $p): array {
            $p = (array) $p;

            $h = [];
            $h['threshold_display_name'] = $p['threshold_display_name'] ?? null;
            $h['amount_cents'] = $p['amount_cents'] ?? null;
            $h['recurring'] = filter_var($p['recurring'] ?? false, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;

            return $h;
        }, $params);
    }

    private function missingAmountCents(array $params): bool
    {
        foreach ($params as $p) {
            if (($p['amount_cents'] ?? null) === null || $p['amount_cents'] === '') {
                return true;
            }
        }

        return false;
    }

    private function duplicatedAmountCents(array $params): bool
    {
        $grouped = [];

        foreach ($params as $p) {
            $key = $p['amount_cents'].'|'.($p['recurring'] ? '1' : '0');
            $grouped[$key] = ($grouped[$key] ?? 0) + 1;
        }

        return in_array(true, array_map(fn (int $count): bool => $count > 1, $grouped), true);
    }

    private function multipleRecurringThresholds(array $params): bool
    {
        return count(array_filter($params, fn (array $p): bool => (bool) $p['recurring'])) > 1;
    }

    private function deleteAllThresholds(): void
    {
        $this->model->usageThresholds()->update(['deleted_at' => now()]);
    }

    private function updateRecurringThreshold(array $params): void
    {
        $recurringParams = null;

        foreach ($params as $p) {
            if ($p['recurring']) {
                $recurringParams = $p;

                break;
            }
        }

        if ($recurringParams === null) {
            return;
        }

        $existingThreshold = $this->thresholds()->first(fn ($t): bool => (bool) $t->recurring);

        if ($existingThreshold !== null) {
            $existingThreshold->amount_cents = (int) $recurringParams['amount_cents'];
            $existingThreshold->threshold_display_name = $recurringParams['threshold_display_name'];
            $existingThreshold->save();
        } else {
            $this->createThreshold($recurringParams, recurring: true);
        }
    }

    private function updateOrCreateThresholds(array $params): void
    {
        foreach ($params as $thresholdParams) {
            if ($thresholdParams['recurring']) {
                continue;
            }

            $existingThreshold = $this->thresholds()->first(
                fn ($t): bool => (int) $t->amount_cents === (int) $thresholdParams['amount_cents'] && ! $t->recurring,
            );

            if ($existingThreshold !== null) {
                if (array_key_exists('threshold_display_name', $thresholdParams)) {
                    $existingThreshold->threshold_display_name = $thresholdParams['threshold_display_name'];
                }

                $existingThreshold->save();
            } else {
                $this->createThreshold($thresholdParams);
            }
        }
    }

    /**
     * The kept thresholds of the model, loaded once per execution.
     *
     * @return \Illuminate\Support\Collection<int, UsageThreshold>
     */
    private function thresholds(): \Illuminate\Support\Collection
    {
        if ($this->thresholdsMemo === null) {
            $this->thresholdsMemo = $this->model->usageThresholds()->get();
        }

        return $this->thresholdsMemo;
    }

    private function createThreshold(array $params, bool $recurring = false): void
    {
        $threshold = new UsageThreshold([
            'organization_id' => $this->model->organization_id,
            'threshold_display_name' => $params['threshold_display_name'],
            'amount_cents' => (int) $params['amount_cents'],
            'recurring' => $recurring,
        ]);

        $threshold->plan_id = $this->model instanceof Plan ? $this->model->id : null;
        $threshold->subscription_id = $this->model instanceof Subscription ? $this->model->id : null;
        $threshold->save();

        $this->thresholdsMemo?->push($threshold);
    }
}
