<?php

declare(strict_types=1);

namespace App\Services\Webhooks\Subscriptions;

use App\Serializers\V1\SubscriptionSerializer;
use App\Services\Webhooks\BaseService;

/**
 * Port of Rails' Webhooks::Subscriptions::CanceledService
 * (app/services/webhooks/subscriptions/canceled_service.rb).
 */
class CanceledService extends BaseService
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
        return 'subscription.canceled';
    }

    protected function objectType(): string
    {
        return 'subscription';
    }
}
