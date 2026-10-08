<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Wallet;
use App\Enums\RecurringTransactionTrigger;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :recurring_transaction_rule factory
 * (spec/factories/recurring_transaction_rules.rb).
 *
 * @extends Factory<\App\Models\RecurringTransactionRule>
 */
class RecurringTransactionRuleFactory extends Factory
{
    public function definition(): array
    {
        $name = $this->faker->name();

        return [
            'wallet_id' => WalletFactory::new(),
            'organization_id' => function (array $attributes): string {
                $wallet = Wallet::query()->find($attributes['wallet_id']);

                return $wallet->organization_id;
            },
            'paid_credits' => '10.00',
            'granted_credits' => '10.00',
            'interval' => 'monthly',
            'trigger' => RecurringTransactionTrigger::Interval->label(),
            'transaction_name' => 'Recurring Transaction Rule',
        ];
    }

    /** Rails after(:build) — target rules never persist a nil grants flag. */
    public function configure(): static
    {
        return $this->afterMaking(function (\App\Models\RecurringTransactionRule $rule): void {
            // The services map the Rails enum NAMES to the stored integers
            // before the model sees them; specs pass the raw names.
            foreach (['method', 'trigger', 'interval'] as $attribute) {
                if (is_string($rule->getAttribute($attribute))) {
                    $rule->setAttribute($attribute, match ($attribute) {
                        'method' => \App\Enums\RecurringTransactionMethod::fromOption($rule->getAttribute($attribute)),
                        'trigger' => RecurringTransactionTrigger::fromOption($rule->getAttribute($attribute)),
                        default => \App\Enums\RecurringTransactionInterval::fromOption($rule->getAttribute($attribute)),
                    });
                }
            }

            if ($rule->methodEnum()?->label() === 'target' && $rule->grants_target_top_up === null) {
                $rule->grants_target_top_up = false;
            }
        });
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
        ]);
    }
}
