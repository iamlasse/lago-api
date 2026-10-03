<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Enums\WalletTransactionType;
use App\Enums\WalletTransactionStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :wallet_transaction factory
 * (spec/factories/wallet_transactions.rb).
 *
 * @extends Factory<WalletTransaction>
 */
class WalletTransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'wallet_id' => WalletFactory::new(),
            'organization_id' => function (array $attributes): string {
                $wallet = Wallet::query()->find($attributes['wallet_id']);

                return $wallet->organization_id;
            },
            'billing_entity_id' => function (array $attributes): ?string {
                $wallet = Wallet::query()->find($attributes['wallet_id']);

                return $wallet->resolvedBillingEntity()?->id;
            },
            'transaction_type' => WalletTransactionType::Inbound,
            'status' => WalletTransactionStatus::Settled,
            'amount' => '1.00',
            'credit_amount' => '1.00',
            'settled_at' => now(),
            'name' => 'Custom Transaction Name',
            'remaining_amount_cents' => 100,
            'invoice_requires_successful_payment' => false,
        ];
    }

    /** Rails trait :failed. */
    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => WalletTransactionStatus::Failed,
            'failed_at' => now(),
        ]);
    }

    /** Rails trait :with_purchase_order_number. */
    public function withPurchaseOrderNumber(): static
    {
        return $this->state(fn () => ['purchase_order_number' => 'PO-123']);
    }

    public function forWallet(Wallet $wallet): static
    {
        return $this->state(fn () => [
            'wallet_id' => $wallet->id,
            'organization_id' => $wallet->organization_id,
            'billing_entity_id' => $wallet->resolvedBillingEntity()?->id,
        ]);
    }
}
