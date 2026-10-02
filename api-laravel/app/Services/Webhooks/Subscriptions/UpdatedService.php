<?php

declare(strict_types=1);

namespace App\Services\Webhooks\Subscriptions;

use App\Serializers\V1\SubscriptionSerializer;
use App\Services\Webhooks\BaseService;

/**
 * Port of Rails' Webhooks::Subscriptions::UpdatedService
 * (app/services/webhooks/subscriptions/updated_service.rb).
 */
class UpdatedService extends BaseService
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
        return 'subscription.updated';
    }

    protected function objectType(): string
    {
        return 'subscription';
    }
}
