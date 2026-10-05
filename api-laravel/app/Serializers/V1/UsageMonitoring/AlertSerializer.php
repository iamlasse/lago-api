<?php

declare(strict_types=1);

namespace App\Serializers\V1\UsageMonitoring;

use App\Models\UsageMonitoring\Alert;
use App\Serializers\Base\ModelSerializer;
use App\Serializers\V1\BillableMetricSerializer;

/**
 * Port of Rails' V1::UsageMonitoring::AlertSerializer
 * (app/serializers/v1/usage_monitoring/alert_serializer.rb).
 */
class AlertSerializer extends ModelSerializer
{
    /** @return array<string, mixed> */
    public function serialize(): array
    {
        /** @var Alert $alert */
        $alert = $this->model;

        return [
            'lago_id' => $alert->id,
            'lago_organization_id' => $alert->organization_id,
            'subscription_external_id' => $alert->subscription_external_id, // DEPRECATED
            'external_subscription_id' => $alert->subscription_external_id,
            'lago_wallet_id' => $alert->wallet_id,
            'wallet_code' => $alert->wallet?->code,
            'alert_type' => $alert->alert_type,
            'code' => $alert->code,
            'name' => $alert->name,
            'direction' => $alert->direction,
            'previous_value' => $alert->previous_value,
            'last_processed_at' => $alert->last_processed_at?->toIso8601String(),
            'thresholds' => $this->formattedThresholds($alert),
            'created_at' => $alert->created_at?->toIso8601String(),
            'billable_metric' => $alert->billable_metric_id !== null ? $this->billableMetric($alert) : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function formattedThresholds(Alert $alert): array
    {
        return $alert->thresholds->map(fn ($threshold): array => [
            'code' => $threshold->code,
            'value' => $threshold->value,
            'recurring' => $threshold->recurring,
        ])->values()->all();
    }

    private function billableMetric(Alert $alert): array
    {
        return (new BillableMetricSerializer($alert->billableMetric))->serialize();
    }
}
