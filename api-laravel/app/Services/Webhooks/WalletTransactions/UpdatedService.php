<?php

declare(strict_types=1);

namespace App\Services\Webhooks\WalletTransactions;

use App\Services\Webhooks\BaseService;
use App\Serializers\V1\WalletTransactionSerializer;

/**
 * Port of Rails' Webhooks::WalletTransactions::UpdatedService
 * (app/services/webhooks/wallet_transactions/updated_service.rb).
 */
class UpdatedService extends BaseService
{
    /** @return array<string, mixed> */
    protected function objectSerializer(): array
    {
        return (new WalletTransactionSerializer(
            $this->object,
            ['root_name' => 'wallet_transaction', 'includes' => ['wallet']],
        ))->serialize();
    }

    protected function webhookType(): string
    {
        return 'wallet_transaction.updated';
    }

    protected function objectType(): string
    {
        return 'wallet_transaction';
    }
}
