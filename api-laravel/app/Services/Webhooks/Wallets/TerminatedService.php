<?php

declare(strict_types=1);

namespace App\Services\Webhooks\Wallets;

use App\Services\Webhooks\BaseService;
use App\Serializers\V1\WalletSerializer;

/**
 * Port of Rails' Webhooks::Wallets::TerminatedService
 * (app/services/webhooks/wallets/terminated_service.rb).
 */
class TerminatedService extends BaseService
{
    /** @return array<string, mixed> */
    protected function objectSerializer(): array
    {
        return (new WalletSerializer(
            $this->object,
            ['root_name' => 'wallet', 'includes' => ['recurring_transaction_rules']],
        ))->serialize();
    }

    protected function webhookType(): string
    {
        return 'wallet.terminated';
    }

    protected function objectType(): string
    {
        return 'wallet';
    }
}
