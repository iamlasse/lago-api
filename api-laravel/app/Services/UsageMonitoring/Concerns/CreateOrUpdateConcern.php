<?php

declare(strict_types=1);

namespace App\Services\UsageMonitoring\Concerns;

use Throwable;
use App\Models\BillableMetric;
use App\Models\UsageMonitoring\AlertThreshold;

/**
 * Port of Rails' UsageMonitoring::Concerns::CreateOrUpdateConcern
 * (app/services/usage_monitoring/concerns/create_or_update_concern.rb) — the
 * shared param validation of CreateAlertService / UpdateAlertService.
 */
trait CreateOrUpdateConcern
{
    /**
     * Rails: CODE_UNIQUE_INDEXES — the partial unique indexes whose violation
     * means "this code is taken by another alert on the same subscription or
     * wallet".
     */
    private const CODE_UNIQUE_INDEXES = [
        'idx_alerts_code_unique_per_subscription',
        'idx_alerts_code_unique_per_wallet',
    ];

    /**
     * Rails: #duplicate_code_error? — Postgres surfaces the violated index
     * name in the constraint field of the unique-violation error.
     */
    protected function duplicateCodeError(Throwable $exception): bool
    {
        return array_any(self::CODE_UNIQUE_INDEXES, fn($index) => str_contains($exception->getMessage(), $index));
    }

    /**
     * Rails: #find_billable_metric_from_params! — by object, id or code; a
     * miss marks the result not_found (billable_metric).
     */
    protected function findBillableMetricFromParams(array $params): ?BillableMetric
    {
        if (array_key_exists('billable_metric', $params) && $params['billable_metric'] !== null) {
            return $params['billable_metric'];
        }

        if (! empty($params['billable_metric_id'])) {
            $metric = $this->organization->billableMetrics()
                ->where('id', $params['billable_metric_id'])
                ->first();

            if ($metric === null) {
                $this->result->notFoundFailure('billable_metric');
            }

            return $metric;
        }

        if (! empty($params['billable_metric_code'])) {
            $metric = $this->organization->billableMetrics()
                ->where('code', $params['billable_metric_code'])
                ->first();

            if ($metric === null) {
                $this->result->notFoundFailure('billable_metric');
            }

            return $metric;
        }

        return null;
    }

    /**
     * Rails: #wallet_alert_code_taken? — a wallet alert's code must be unique
     * across the wallet regardless of alert_type.
     */
    protected function walletAlertCodeTaken(
        ?string $walletId,
        ?string $code,
        string $alertType,
        ?string $excludingId = null,
    ): bool {
        if (empty($walletId) || empty($code)) {
            return false;
        }

        $scope = $this->organization->alerts()
            ->where('wallet_id', $walletId)
            ->where('code', $code)
            ->where('alert_type', '!=', $alertType);

        if ($excludingId !== null) {
            $scope->where('id', '!=', $excludingId);
        }

        return $scope->exists();
    }

    /**
     * Rails: #duplicate_threshold_values? — same (value, recurring) pair twice.
     *
     * @param  list<array<string, mixed>>  $thresholds
     */
    protected function duplicateThresholdValues(array $thresholds): bool
    {
        $keys = array_map(
            fn (array $t): string => (string) ($t['value'] ?? null).'|'.($this->recurringParam($t) ? '1' : '0'),
            $thresholds,
        );

        return count($keys) !== count(array_unique($keys));
    }

    /** Rails: #recurring_param? — ActiveModel::Boolean cast, default false. */
    protected function recurringParam(array $threshold): bool
    {
        $value = $threshold['recurring'] ?? null;

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
    }

    /**
     * Rails: #all_threshold_values_present?.
     *
     * @param  list<array<string, mixed>>  $thresholds
     */
    protected function allThresholdValuesPresent(array $thresholds): bool
    {
        return array_all($thresholds, fn($t) => !(($t['value'] ?? null) === null));
    }

    /**
     * Rails: #all_threshold_values_numeric? — numeric or numeric string.
     *
     * @param  list<array<string, mixed>>  $thresholds
     */
    protected function allThresholdValuesNumeric(array $thresholds): bool
    {
        return array_all($thresholds, fn($t) => $this->validNumericValue($t['value'] ?? null));
    }

    /**
     * Rails: #validate_notify_on! — the array column's values must be known,
     * `triggered` is mandatory, `resolved` requires a non-recurring threshold
     * with a code unique among the batch.
     *
     * @param  list<array<string, mixed>>  $thresholds
     */
    protected function validateNotifyOn(array $thresholds): void
    {
        foreach ($thresholds as $t) {
            foreach ($this->notifyOnFor($t) as $value) {
                if (! in_array($value, AlertThreshold::NOTIFY_ON_VALUES, true)) {
                    $this->result->singleValidationFailure('value_is_invalid', 'thresholds:notify_on');

                    return;
                }
            }
        }

        foreach ($thresholds as $t) {
            if (! in_array(AlertThreshold::NOTIFY_ON_TRIGGERED, $this->notifyOnFor($t), true)) {
                $this->result->singleValidationFailure('triggered_is_mandatory', 'thresholds:notify_on');

                return;
            }
        }

        $optingIn = array_values(array_filter(
            $thresholds,
            fn (array $t): bool => in_array(AlertThreshold::NOTIFY_ON_RESOLVED, $this->notifyOnFor($t), true),
        ));

        if ($optingIn === []) {
            return;
        }

        foreach ($optingIn as $t) {
            if ($this->recurringParam($t)) {
                $this->result->singleValidationFailure('recurring_not_supported', 'thresholds:notify_on');

                return;
            }

            if (($t['code'] ?? null) === null || $t['code'] === '') {
                $this->result->singleValidationFailure('value_is_mandatory', 'thresholds:code');

                return;
            }
        }

        if ($this->optedInCodeNotUnique($thresholds, $optingIn)) {
            $this->result->singleValidationFailure('duplicate_threshold_codes', 'thresholds');
        }
    }

    /**
     * Rails: #all_recurring_threshold_values_positive?.
     *
     * @param  list<array<string, mixed>>  $thresholds
     */
    protected function allRecurringThresholdValuesPositive(array $thresholds): bool
    {
        foreach ($thresholds as $t) {
            $value = $t['value'] ?? null;

            if ($this->recurringParam($t) && ! ($value > 0)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Rails: #notify_on_for.
     *
     * @param  array<string, mixed>  $threshold
     * @return list<string>
     */
    protected function notifyOnFor(array $threshold): array
    {
        $values = $threshold['notify_on'] ?? null;

        if ($values === null) {
            return [AlertThreshold::NOTIFY_ON_TRIGGERED];
        }

        return array_map(fn ($v): string => (string) $v, (array) $values);
    }

    /**
     * Rails: #opted_in_code_not_unique?.
     *
     * @param  list<array<string, mixed>>  $thresholds
     * @param  list<array<string, mixed>>  $optingIn
     */
    protected function optedInCodeNotUnique(array $thresholds, array $optingIn): bool
    {
        $codes = array_values(array_filter(
            array_map(fn (array $t): ?string => ($t['code'] ?? null) ?: null, $thresholds),
        ));

        foreach ($optingIn as $t) {
            $code = ($t['code'] ?? null) ?: null;

            if ($code !== null && count(array_keys($codes, $code, true)) > 1) {
                return true;
            }
        }

        return false;
    }

    protected function validNumericValue(mixed $value): bool
    {
        if (is_int($value) || is_float($value)) {
            return true;
        }

        if (is_string($value)) {
            if (mb_trim($value) === '') {
                return false;
            }

            return is_numeric($value);
        }

        return false;
    }
}
