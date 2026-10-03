<?php

declare(strict_types=1);

namespace App\Services\Webhooks\Wallets;

use App\Services\Webhooks\BaseService;
use App\Serializers\V1\WalletSerializer;

/**
 * Port of Rails' Webhooks::Wallets::UpdatedService
 * (app/services/webhooks/wallets/updated_service.rb).
 */
class UpdatedService extends BaseService
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
        return 'wallet.updated';
    }

    protected function objectType(): string
    {
        return 'wallet';
    }
}
