<?php

declare(strict_types=1);

namespace App\Services\Webhooks\WalletTransactions;

use App\Services\Webhooks\BaseService;
use App\Serializers\V1\WalletTransactionSerializer;

/**
 * Port of Rails' Webhooks::WalletTransactions::CreatedService
 * (app/services/webhooks/wallet_transactions/created_service.rb).
 */
class CreatedService extends BaseService
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
        return 'wallet_transaction.created';
    }

    protected function objectType(): string
    {
        return 'wallet_transaction';
    }
}
