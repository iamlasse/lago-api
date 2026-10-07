<?php

declare(strict_types=1);

namespace App\Services\Webhooks\Subscriptions;

use App\Services\Webhooks\BaseService;
use App\Serializers\V1\SubscriptionSerializer;

/**
 * Port of Rails' Webhooks::Subscriptions::TerminationAlertService
 * (app/services/webhooks/subscriptions/termination_alert_service.rb).
 */
class TerminationAlertService extends BaseService
{
    /** @return array<string, mixed> */
    protected function objectSerializer(): array
    {
        return (new SubscriptionSerializer(
            $this->object,
            ['root_name' => 'subscription', 'includes' => ['plan', 'customer']],
        ))->serialize();
    }

    protected function webhookType(): string
    {
        return 'subscription.termination_alert';
    }

    protected function objectType(): string
    {
        return 'subscription';
    }
}
