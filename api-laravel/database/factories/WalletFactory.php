<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Wallet;
use App\Enums\WalletStatus;
use Illuminate\Support\Str;
use App\Enums\WalletTransactionType;
use App\Enums\WalletTransactionStatus;
use App\Enums\WalletTransactionCreditStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :wallet factory (spec/factories/wallets.rb).
 *
 * @extends Factory<Wallet>
 */
class WalletFactory extends Factory
{
    public function definition(): array
    {
        $name = $this->faker->name();

        return [
            'customer_id' => CustomerFactory::new(),
            'organization_id' => function (array $attributes): string {
                $customer = \App\Models\Customer::query()->find($attributes['customer_id']);

                return $customer->organization_id;
            },
            'name' => $name,
            'code' => Str::slug($name, '_') ?: 'default',
            'status' => WalletStatus::Active,
            'balance_currency' => 'EUR',
            'consumed_amount_currency' => 'EUR',
            'rate_amount' => '1.00',
            'credits_balance' => 0,
            'balance_cents' => 0,
            'consumed_credits' => 0,
            'invoice_requires_successful_payment' => false,
            'traceable' => true,
        ];
    }

    /** Rails trait :terminated. */
    public function terminated(): static
    {
        return $this->state(fn () => [
            'status' => WalletStatus::Terminated,
            'terminated_at' => now(),
        ]);
    }

    /** Rails trait :with_top_up_limits. */
    public function withTopUpLimits(): static
    {
        return $this->state(fn () => [
            'paid_top_up_min_amount_cents' => random_int(100, 1000),
            'paid_top_up_max_amount_cents' => random_int(2000, 5000),
        ]);
    }

    /** Rails trait :with_purchase_order_number. */
    public function withPurchaseOrderNumber(): static
    {
        return $this->state(fn () => ['purchase_order_number' => 'PO-123']);
    }

    /** Rails trait :with_inbound_transaction. */
    public function withInboundTransaction(): static
    {
        return $this->afterCreating(function (Wallet $wallet): void {
            WalletTransactionFactory::new()->create([
                'wallet_id' => $wallet->id,
                'organization_id' => $wallet->organization_id,
                'transaction_type' => WalletTransactionType::Inbound,
                'transaction_status' => WalletTransactionCreditStatus::Granted,
                'status' => WalletTransactionStatus::Settled,
                'amount' => $wallet->credits_balance,
                'credit_amount' => $wallet->credits_balance,
                'remaining_amount_cents' => (int) $wallet->balance_cents,
            ]);
        });
    }

    public function forCustomer(\App\Models\Customer $customer): static
    {
        return $this->state(fn () => [
            'customer_id' => $customer->id,
            'organization_id' => $customer->organization_id,
        ]);
    }
}
