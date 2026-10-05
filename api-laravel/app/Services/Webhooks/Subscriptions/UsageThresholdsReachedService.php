<?php

declare(strict_types=1);

namespace App\Services\Webhooks\Subscriptions;

use App\Services\Webhooks\BaseService;
use App\Serializers\V1\SubscriptionSerializer;

/**
 * Port of Rails' Webhooks::Subscriptions::UsageThresholdsReachedService
 * (app/services/webhooks/subscriptions/usage_thresholds_reached_service.rb).
 */
class UsageThresholdsReachedService extends BaseService
{
    /** @return array<string, mixed> */
    protected function objectSerializer(): array
    {
        return (new SubscriptionSerializer(
            $this->object,
            [
                'root_name' => 'subscription',
                'includes' => ['plan', 'customer', 'usage_threshold', 'applicable_usage_thresholds'],
                'usage_threshold' => $this->options['usage_threshold'] ?? null,
            ],
        ))->serialize();
    }

    protected function webhookType(): string
    {
        return 'subscription.usage_threshold_reached';
    }

    protected function objectType(): string
    {
        return 'subscription';
    }
}
