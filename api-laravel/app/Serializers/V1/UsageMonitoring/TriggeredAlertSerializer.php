<?php

declare(strict_types=1);

namespace App\Serializers\V1\UsageMonitoring;

use App\Models\UsageMonitoring\Alert;
use App\Serializers\Base\ModelSerializer;
use App\Models\UsageMonitoring\TriggeredAlert;

/**
 * Port of Rails' V1::UsageMonitoring::TriggeredAlertSerializer
 * (app/serializers/v1/usage_monitoring/triggered_alert_serializer.rb) — the
 * alert.triggered webhook payload.
 */
class TriggeredAlertSerializer extends ModelSerializer
{
    /** @return array<string, mixed> */
    public function serialize(): array
    {
        /** @var TriggeredAlert $triggeredAlert */
        $triggeredAlert = $this->model;

        /** @var Alert $alert */
        $alert = $triggeredAlert->alert;

        $externalCustomerId = $triggeredAlert->subscription?->customer?->external_id
            ?? $triggeredAlert->wallet?->customer?->external_id;

        return [
            'lago_id' => $triggeredAlert->id,
            'lago_organization_id' => $triggeredAlert->organization_id,
            'lago_alert_id' => $alert->id,
            'lago_subscription_id' => $triggeredAlert->subscription_id,
            'external_subscription_id' => $alert->subscription_external_id,
            'lago_wallet_id' => $triggeredAlert->wallet_id,
            'external_customer_id' => $externalCustomerId,
            'billable_metric_code' => $alert->billableMetric?->code,
            'alert_name' => $alert->name,
            'alert_code' => $alert->code,
            'alert_type' => $alert->alert_type,
            'current_value' => $triggeredAlert->current_value,
            'previous_value' => $triggeredAlert->previous_value,
            'crossed_thresholds' => $triggeredAlert->crossed_thresholds,
            'triggered_at' => $triggeredAlert->triggered_at->toIso8601String(),

            'subscription_external_id' => $alert->subscription_external_id, // DEPRECATED
            'customer_external_id' => $externalCustomerId, // DEPRECATED
        ];
    }
}
