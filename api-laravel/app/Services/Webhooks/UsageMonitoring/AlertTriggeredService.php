<?php

declare(strict_types=1);

namespace App\Services\Webhooks\UsageMonitoring;

use App\Services\Webhooks\BaseService;
use App\Serializers\V1\UsageMonitoring\TriggeredAlertSerializer;

/**
 * Port of Rails' Webhooks::UsageMonitoring::AlertTriggeredService
 * (app/services/webhooks/usage_monitoring/alert_triggered_service.rb).
 */
class AlertTriggeredService extends BaseService
{
    /** @return array<string, mixed> */
    protected function objectSerializer(): array
    {
        return (new TriggeredAlertSerializer(
            $this->object,
            ['root_name' => $this->objectType()],
        ))->serialize();
    }

    protected function webhookType(): string
    {
        return 'alert.triggered';
    }

    protected function objectType(): string
    {
        return 'triggered_alert';
    }
}
