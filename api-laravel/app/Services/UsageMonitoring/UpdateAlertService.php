<?php

declare(strict_types=1);

namespace App\Services\UsageMonitoring;

use App\Services\BaseResult;
use Illuminate\Support\Facades\DB;
use App\Models\UsageMonitoring\Alert;
use App\Models\UsageMonitoring\AlertThreshold;
use App\Models\UsageMonitoring\SubscriptionActivity;
use App\Services\UsageMonitoring\Concerns\CreateOrUpdateConcern;

/**
 * Port of Rails' UsageMonitoring::UpdateAlertService
 * (app/services/usage_monitoring/update_alert_service.rb).
 */
class UpdateAlertService extends BaseService
{
    use CreateOrUpdateConcern;

    private BaseResult $result;

    public function __construct(
        private readonly ?Alert $alert,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $this->result = BaseResult::of('alert');
        $result = $this->result;
        $alert = $this->alert;

        if ($alert === null) {
            return $result->notFoundFailure('alert');
        }

        $params = $this->params;
        $thresholds = $params['thresholds'] ?? null;

        if (array_key_exists('thresholds', $params) && $thresholds !== null && count($thresholds) > AlertThreshold::SOFT_LIMIT) {
            return $result->singleValidationFailure('too_many_thresholds', 'thresholds');
        }

        if ($thresholds !== null && $thresholds !== []) {
            if ($this->duplicateThresholdValues($thresholds)) {
                return $result->singleValidationFailure('duplicate_threshold_values', 'thresholds');
            }

            if (! $this->allThresholdValuesPresent($thresholds)) {
                return $result->singleValidationFailure('value_is_mandatory', 'thresholds:value');
            }

            if (! $this->allThresholdValuesNumeric($thresholds)) {
                return $result->singleValidationFailure('value_is_invalid', 'thresholds:value');
            }

            if (! $this->allRecurringThresholdValuesPositive($thresholds)) {
                return $result->singleValidationFailure('recurring_value_is_negative', 'thresholds:value');
            }

            $this->validateNotifyOn($thresholds);

            if ($result->failure()) {
                return $result;
            }
        }

        $result->alert = $alert;

        $billableMetric = $this->findBillableMetricFromParams($params);

        if ($result->failure()) {
            return $result;
        }

        if (array_key_exists('code', $params) && $this->walletAlertCodeTaken(
            walletId: $alert->wallet_id,
            code: $params['code'],
            alertType: (string) $alert->alert_type,
            excludingId: $alert->id,
        )) {
            return $result->singleValidationFailure('value_already_exist', 'code');
        }

        try {
            DB::transaction(function () use ($alert, $params, $billableMetric, $thresholds): void {
                // Rails: alert.with_lock — SELECT ... FOR UPDATE.
                $locked = Alert::query()->whereKey($alert->id)->lockForUpdate()->first() ?? $alert;

                if (array_key_exists('name', $params)) {
                    $locked->name = $params['name'];
                }

                if (array_key_exists('code', $params)) {
                    $locked->code = $params['code'];
                }

                if ($billableMetric !== null) {
                    $locked->billableMetric()->associate($billableMetric);
                }

                $locked->save();

                if ($thresholds !== null && $thresholds !== []) {
                    AlertThreshold::query()
                        ->where('usage_monitoring_alert_id', $locked->id)
                        ->delete();

                    foreach ($this->prepareThresholds($thresholds, (string) $locked->organization_id) as $row) {
                        AlertThreshold::query()->create($row + ['usage_monitoring_alert_id' => $locked->id]);
                    }
                }
            });
        } catch (\App\Services\Failures\FailedResult $e) {
            return $result->failWithError($e);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            if ($this->duplicateCodeError($e)) {
                return $result->singleValidationFailure('value_already_exist', 'code');
            }

            // Only one alert per [alert_type, billable_metric] pair is allowed.
            return $result->singleValidationFailure('alert_already_exists', 'base');
        }

        $this->trackSubscriptionActivity($alert);
        $this->processWalletAlerts($alert);

        $result->alert = $alert->refresh();

        return $result;
    }

    private function trackSubscriptionActivity(Alert $alert): void
    {
        if ($alert->subscription_external_id === null) {
            return;
        }

        $activeSubscription = $alert->organization->subscriptions()
            ->active()
            ->where('external_id', $alert->subscription_external_id)
            ->first();

        if ($activeSubscription === null) {
            return;
        }

        if (! $this->premium()) {
            return;
        }

        SubscriptionActivity::insertFor($activeSubscription, (string) $alert->organization_id);
    }

    private function processWalletAlerts(Alert $alert): void
    {
        if ($alert->wallet_id === null) {
            return;
        }

        if (! $this->premium()) {
            return;
        }

        if (! $alert->wallet?->isActive()) {
            return;
        }

        dispatch(new \App\Jobs\UsageMonitoring\ProcessWalletAlertsJob($alert->wallet_id));
    }
}
