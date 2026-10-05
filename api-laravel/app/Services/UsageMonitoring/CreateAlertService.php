<?php

declare(strict_types=1);

namespace App\Services\UsageMonitoring;

use App\Models\Wallet;
use App\Models\Subscription;
use App\Services\BaseResult;
use Illuminate\Support\Facades\DB;
use App\Models\UsageMonitoring\Alert;
use App\Models\UsageMonitoring\AlertThreshold;
use App\Models\UsageMonitoring\SubscriptionActivity;
use App\Services\UsageMonitoring\Concerns\CreateOrUpdateConcern;

/**
 * Port of Rails' UsageMonitoring::CreateAlertService
 * (app/services/usage_monitoring/create_alert_service.rb).
 */
class CreateAlertService extends BaseService
{
    use CreateOrUpdateConcern;

    private BaseResult $result;

    public function __construct(
        private readonly \App\Models\Organization $organization,
        private readonly Subscription|Wallet $alertable,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public static function result(): BaseResult
    {
        return BaseResult::of('alert');
    }

    public function execute(): BaseResult
    {
        $this->result = static::result();
        $result = $this->result;
        $params = $this->params;

        $alertType = $params['alert_type'] ?? null;

        if (in_array($alertType, ['lifetime_usage_amount'], true) && ! $this->organization->usingLifetimeUsage()) {
            return $result->singleValidationFailure('feature_not_available', 'alert_type');
        }

        if (in_array($alertType, ['billable_metric_lifetime_usage_units'], true)
            && ! $this->organization->granularLifetimeUsageEnabled()) {
            return $result->singleValidationFailure('feature_not_available', 'alert_type');
        }

        if (($alertType ?? '') === '') {
            return $result->validationFailure(['alert_type' => ['value_is_mandatory', 'value_is_invalid']]);
        }

        if (! array_key_exists($alertType, Alert::STI_MAPPING)) {
            return $result->singleValidationFailure('invalid_type', 'alert_type');
        }

        $alertable = $this->alertable;

        if ($alertable instanceof Wallet && ! in_array($alertType, Alert::WALLET_TYPES, true)) {
            return $result->singleValidationFailure('invalid_type', 'alert_type');
        }

        if ($alertable instanceof Subscription && in_array($alertType, Alert::WALLET_TYPES, true)) {
            return $result->singleValidationFailure('invalid_type', 'alert_type');
        }

        $thresholds = $params['thresholds'] ?? null;

        if ($thresholds === null || $thresholds === []) {
            return $result->singleValidationFailure('value_is_mandatory', 'thresholds');
        }

        if (count($thresholds) > AlertThreshold::SOFT_LIMIT) {
            return $result->singleValidationFailure('too_many_thresholds', 'thresholds');
        }

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

        $billableMetric = $this->findBillableMetricFromParams($params);

        if ($result->failure()) {
            return $result;
        }

        $subscription = $alertable instanceof Subscription ? $alertable : null;
        $wallet = $alertable instanceof Wallet ? $alertable : null;

        if ($this->walletAlertCodeTaken(
            walletId: $wallet?->id,
            code: $params['code'] ?? null,
            alertType: $alertType,
        )) {
            return $result->singleValidationFailure('value_already_exist', 'code');
        }

        $alert = new Alert([
            'organization_id' => $this->organization->id,
            'subscription_external_id' => $subscription?->external_id,
            'wallet_id' => $wallet?->id,
            'billable_metric_id' => $billableMetric?->id,
            'alert_type' => (string) $alertType,
            'name' => $params['name'] ?? null,
            'code' => $params['code'] ?? null,
            'direction' => $this->directionForAlert($alertType),
        ]);

        try {
            DB::transaction(function () use ($alert, $alertable, $thresholds): void {
                $errors = $alert->validateAttributes();

                if ($errors !== []) {
                    $this->result->recordValidationFailure($errors)->raiseIfError();
                }

                $alert->save();

                // NOTE: the alert row is inserted before the alertable is locked, so this takes
                // the same order as evaluation; the lock is what keeps the baseline in step with
                // concurrent balance changes. FOR NO KEY UPDATE still conflicts with a balance
                // update, but not with the key-share lock the insert above took on the same row,
                // which two concurrent creates would otherwise deadlock upgrading.
                if ($alert->decreasing()) {
                    $locked = $alertable instanceof Wallet
                        ? Wallet::query()->whereKey($alertable->id)->lock('FOR NO KEY UPDATE')->first()
                        : $alertable;

                    $alert->previous_value = $alert->findValue($locked);
                    $alert->save();
                }

                foreach ($this->prepareThresholds($thresholds, (string) $this->organization->id) as $row) {
                    AlertThreshold::query()->create($row + ['usage_monitoring_alert_id' => $alert->id]);
                }
            });

            $result->alert = $alert;
        } catch (\App\Services\Failures\FailedResult $e) {
            return $result->failWithError($e);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            if ($this->duplicateCodeError($e)) {
                return $result->singleValidationFailure('value_already_exist', 'code');
            }

            // Only one alert per [alert_type, billable_metric] pair is allowed.
            return $result->singleValidationFailure('alert_already_exists', 'base');
        }

        if ($subscription !== null) {
            $this->trackSubscriptionActivity($subscription);
        }

        if ($wallet !== null) {
            $this->processWalletAlerts($wallet);
        }

        return $result;
    }

    private function directionForAlert(string $alertType): string
    {
        return in_array($alertType, Alert::WALLET_TYPES, true) ? 'decreasing' : 'increasing';
    }

    private function trackSubscriptionActivity(Subscription $subscription): void
    {
        if (! $this->premium()) {
            return;
        }

        if (! $subscription->active()) {
            return;
        }

        SubscriptionActivity::insertFor($subscription, $this->organization->id);
    }

    private function processWalletAlerts(Wallet $wallet): void
    {
        if (! $this->premium()) {
            return;
        }

        if (! $wallet->isActive()) {
            return;
        }

        \App\Jobs\UsageMonitoring\ProcessWalletAlertsJob::dispatch($wallet->id);
    }
}
