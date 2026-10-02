<?php

declare(strict_types=1);

namespace App\Services\Webhooks\Subscriptions;

use App\Serializers\V1\SubscriptionSerializer;
use App\Services\Webhooks\BaseService;

/**
 * Port of Rails' Webhooks::Subscriptions::StartedService
 * (app/services/webhooks/subscriptions/started_service.rb) — the
 * "subscription.created" webhook is emitted as subscription.started.
 */
class StartedService extends BaseService
{
    /** @return array<string, mixed> */
    protected function objectSerializer(): array
    {
        // TODO(integration): entitlements include once the entitlement models
        // are ported (the serializer currently ignores it).
        return (new SubscriptionSerializer(
            $this->object,
            ['root_name' => 'subscription', 'includes' => ['plan', 'customer', 'entitlements']],
        ))->serialize();
    }

    protected function webhookType(): string
    {
        return 'subscription.started';
    }

    protected function objectType(): string
    {
        return 'subscription';
    }
}
