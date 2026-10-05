<?php

declare(strict_types=1);

namespace App\Services\UsageMonitoring;

use App\Models\Wallet;
use App\Models\Subscription;
use App\Services\BaseResult;
use Illuminate\Support\Facades\DB;
use App\Models\UsageMonitoring\Alert;
use App\Models\UsageMonitoring\TriggeredAlert;

/**
 * Port of Rails' UsageMonitoring::ProcessAlertService
 * (app/services/usage_monitoring/process_alert_service.rb) — evaluates one
 * alert under its row lock: reads the current value from the passed metrics,
 * records a TriggeredAlert + webhook for every crossed threshold and refreshes
 * the previous_value baseline.
 */
class ProcessAlertService extends BaseService
{
    public function __construct(
        private readonly Alert $alert,
        private readonly mixed $currentMetrics,
        private readonly Subscription|Wallet $alertable,
        private readonly ?string $expectedBillableMetricId = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('alert');
        $alert = $this->alert;
        $now = now();

        // Rails: alert.with_lock — the whole evaluate step runs under the row
        // lock so concurrent balance/usage updates serialize on it.
        DB::transaction(function () use ($alert, $now): void {
            $locked = Alert::query()->whereKey($alert->id)->lockForUpdate()->first() ?? $alert;

            // NOTE: the caller measured usage for one metric, so a metric changed
            // since then makes that usage unrelated to the configuration now held
            // under the lock.
            if ($this->staleMetric($locked)) {
                return;
            }

            // NOTE: read inside the lock so the metric selection and the thresholds
            // come from the same configuration.
            $locked->setRelation('thresholds', $locked->thresholds()->get());

            $current = $locked->findValue($this->currentMetrics);

            // NOTE: current is nil if the alert is set for a billable metric which
            // is not part of any charges of the plan.
            if ($current !== null) {
                $this->evaluate($locked, $current, $now);
            }

            $locked->last_processed_at = $now;
            $locked->save();

            $alert->setRawAttributes($locked->getAttributes(), true);
        });

        $result->alert = $alert;

        return $result;
    }

    private function staleMetric(Alert $alert): bool
    {
        return $this->expectedBillableMetricId !== null
            && (string) $alert->billable_metric_id !== $this->expectedBillableMetricId;
    }

    private function evaluate(Alert $alert, string $current, $now): void
    {
        $crossedThresholdValues = $alert->findThresholdsCrossed($current);

        if ($crossedThresholdValues !== []) {
            $this->recordTrigger($alert, $crossedThresholdValues, $current, $now);
        }

        $alert->previous_value = $current;
    }

    private function recordTrigger(Alert $alert, array $crossedThresholdValues, string $current, $now): void
    {
        $triggeredAlert = TriggeredAlert::query()->create([
            'organization_id' => $alert->organization_id,
            'usage_monitoring_alert_id' => $alert->id,
            'subscription_id' => $this->alertable instanceof Subscription ? $this->alertable->id : null,
            'wallet_id' => $this->alertable instanceof Wallet ? $this->alertable->id : null,
            'current_value' => $current,
            'previous_value' => (string) $alert->previous_value,
            'crossed_thresholds' => $alert->formattedCrossedThresholds($crossedThresholdValues),
            'triggered_at' => $now,
            'kind' => 'triggered',
        ]);

        \App\Jobs\SendWebhookJob::performLater('alert.triggered', $triggeredAlert);
    }
}
