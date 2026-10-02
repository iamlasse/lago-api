<?php

declare(strict_types=1);

namespace App\Services\Webhooks\Subscriptions;

use App\Serializers\V1\SubscriptionSerializer;
use App\Services\Webhooks\BaseService;

/**
 * Port of Rails' Webhooks::Subscriptions::TerminatedService
 * (app/services/webhooks/subscriptions/terminated_service.rb).
 */
class TerminatedService extends BaseService
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
        return 'subscription.terminated';
    }

    protected function objectType(): string
    {
        return 'subscription';
    }
}
