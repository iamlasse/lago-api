<?php

declare(strict_types=1);

use App\Models\Wallet;
use App\Models\Customer;
use Illuminate\Support\Facades\Date;
use App\Models\RecurringTransactionRule;
use App\Enums\RecurringTransactionMethod;
use App\Enums\RecurringTransactionTrigger;
use App\Enums\RecurringTransactionInterval;
use App\Enums\RecurringTransactionRuleStatus;

/**
 * Port of Rails' spec/models/recurring_transaction_rule_spec.rb.
 */
uses()->group('ledger:model:RecurringTransactionRule');

beforeEach(function (): void {
    Date::setTestNow();
});

function ruleWallet(array $overrides = []): Wallet
{
    return Wallet::factory()->create(array_merge(['rate_amount' => '1'], $overrides));
}

/**
 * The services map the Rails enum NAMES to the stored integers before the
 * model sees them; normalize the spec's string attributes the same way.
 */
function ruleAttributes(array $overrides = []): array
{
    $map = [
        'method' => RecurringTransactionMethod::class,
        'trigger' => RecurringTransactionTrigger::class,
        'interval' => RecurringTransactionInterval::class,
    ];

    foreach ($map as $key => $enum) {
        if (isset($overrides[$key]) && is_string($overrides[$key])) {
            $overrides[$key] = $enum::fromOption($overrides[$key]);
        }
    }

    if (($overrides['method'] ?? null) === RecurringTransactionMethod::Target->value
        && ! array_key_exists('grants_target_top_up', $overrides)) {
        $overrides['grants_target_top_up'] = false;
    }

    return $overrides;
}

function makeRule(array $overrides = []): RecurringTransactionRule
{
    /** @var RecurringTransactionRule $rule */
    return RecurringTransactionRule::factory()->make(ruleAttributes($overrides));
}

it('defines the Rails enum values', function (): void {
    expect(RecurringTransactionRule::INTERVALS)->toBe(['weekly', 'monthly', 'quarterly', 'yearly', 'semiannual'])
        ->and(RecurringTransactionRule::METHODS)->toBe(['fixed', 'target'])
        ->and(RecurringTransactionRule::TRIGGERS)->toBe(['interval', 'threshold'])
        ->and(RecurringTransactionRule::STATUSES)->toBe(['active', 'terminated'])
        ->and(RecurringTransactionInterval::fromOption('yearly'))->toBe(3)
        ->and(RecurringTransactionInterval::fromOption('semiannual'))->toBe(4);
})->group('ledger:model:RecurringTransactionRule');

it('delegates customer to the wallet', function (): void {
    $customer = Customer::factory()->create();
    $wallet = Wallet::factory()->forCustomer($customer)->create();
    $rule = RecurringTransactionRule::factory()->forWallet($wallet)->create();

    expect($rule->customer()->id)->toBe($customer->id);
})->group('ledger:model:RecurringTransactionRule');

describe('validations', function (): void {
    it('rejects a transaction_name longer than 255 characters and accepts nil', function (): void {
        expect(makeRule(['transaction_name' => str_repeat('a', 256)])->validateAttributes())
            ->toHaveKey('transaction_name')
            ->and(makeRule(['transaction_name' => null])->validateAttributes())->toBe([]);
    });

    it('accepts a nil grants_target_top_up on target rules (legacy rows)', function (): void {
        $rule = makeRule(['method' => 'target']);
        $rule->grants_target_top_up = null;

        expect($rule->validateAttributes())->toBe([]);
    });

    it('accepts true and false grants_target_top_up on target rules', function (): void {
        expect(makeRule(['method' => 'target', 'grants_target_top_up' => true])->validateAttributes())->toBe([])
            ->and(makeRule(['method' => 'target', 'grants_target_top_up' => false])->validateAttributes())->toBe([]);
    });

    it('rejects true and false grants_target_top_up on non-target rules and accepts nil', function (): void {
        expect(makeRule(['method' => 'fixed', 'grants_target_top_up' => true])->validateAttributes())
            ->toHaveKey('grants_target_top_up')
            ->and(makeRule(['method' => 'fixed', 'grants_target_top_up' => false])->validateAttributes())
            ->toHaveKey('grants_target_top_up')
            ->and(makeRule(['method' => 'fixed', 'grants_target_top_up' => null])->validateAttributes())->toBe([]);
    });

    it('rejects a target below the threshold on target+threshold rules', function (): void {
        $rule = makeRule(['method' => 'target', 'trigger' => 'threshold', 'target_ongoing_balance' => 50, 'threshold_credits' => 100]);

        expect($rule->validateAttributes())->toHaveKey('target_ongoing_balance');
    });

    it('accepts a target equal to or above the threshold', function (): void {
        expect(makeRule(['method' => 'target', 'trigger' => 'threshold', 'target_ongoing_balance' => 100, 'threshold_credits' => 100])->validateAttributes())->toBe([])
            ->and(makeRule(['method' => 'target', 'trigger' => 'threshold', 'target_ongoing_balance' => 150, 'threshold_credits' => 100])->validateAttributes())->toBe([]);
    });

    it('does not block terminating a legacy invalid record when the relevant fields are unchanged', function (): void {
        $wallet = ruleWallet();
        $rule = RecurringTransactionRule::factory()->forWallet($wallet)->create([
            'method' => 'target',
            'trigger' => 'threshold',
            'target_ongoing_balance' => 50,
            'threshold_credits' => 100,
        ]);

        // The port validates explicitly via validateAttributes(); a plain
        // status save never runs it — mirroring Rails' `save!(validate: false)`
        // semantics of the spec.
        $rule->markAsTerminated();

        expect($rule->refresh()->isTerminated())->toBeTrue();
    });

    it('ignores the threshold comparison when trigger is interval', function (): void {
        $rule = makeRule(['method' => 'target', 'trigger' => 'interval', 'target_ongoing_balance' => 50, 'threshold_credits' => 100]);

        expect($rule->validateAttributes())->toBe([]);
    });

    it('normalizes and validates the purchase order number', function (): void {
        $rule = makeRule(['purchase_order_number' => '  PO-1  ']);
        $rule->validateAttributes();

        expect($rule->purchase_order_number)->toBe('PO-1');

        expect(makeRule(['purchase_order_number' => str_repeat('a', 256)])->validateAttributes())
            ->toBe(['purchase_order_number' => ['value_is_too_long']]);
    });
});

describe('scopes', function (): void {
    it('returns the right records for active, eligible_for_termination and expired', function (): void {
        $activeRule = RecurringTransactionRule::factory()->create(['status' => RecurringTransactionRuleStatus::Active, 'expiration_at' => null]);
        $futureRule = RecurringTransactionRule::factory()->create(['status' => RecurringTransactionRuleStatus::Active, 'expiration_at' => now()->addDay()]);
        $expiredRule = RecurringTransactionRule::factory()->create(['status' => RecurringTransactionRuleStatus::Active, 'expiration_at' => now()->subDay()]);
        $terminatedRule = RecurringTransactionRule::factory()->create(['status' => RecurringTransactionRuleStatus::Terminated, 'expiration_at' => now()->subDay()]);

        expect(RecurringTransactionRule::query()->active()->get()->map->id->all())
            ->toEqualCanonicalizing([$activeRule->id, $futureRule->id])
            ->and(RecurringTransactionRule::query()->eligibleForTermination()->get()->map->id->all())
            ->toBe([$expiredRule->id])
            ->and(RecurringTransactionRule::query()->expired()->get()->map->id->all())
            ->toEqualCanonicalizing([$expiredRule->id, $terminatedRule->id]);
    });
});

describe('#currentlyActive', function (): void {
    it('reflects the status and expiration', function (): void {
        expect(makeRule(['status' => RecurringTransactionRuleStatus::Active, 'expiration_at' => null])->currentlyActive())->toBeTrue()
            ->and(makeRule(['status' => RecurringTransactionRuleStatus::Active, 'expiration_at' => now()->addDay()])->currentlyActive())->toBeTrue()
            ->and(makeRule(['status' => RecurringTransactionRuleStatus::Active, 'expiration_at' => now()->subDay()])->currentlyActive())->toBeFalse()
            ->and(makeRule(['status' => RecurringTransactionRuleStatus::Terminated, 'expiration_at' => null])->currentlyActive())->toBeFalse();
    });
});

describe('#markAsTerminated', function (): void {
    it('marks the rule as terminated', function (): void {
        $rule = RecurringTransactionRule::factory()->create(['status' => RecurringTransactionRuleStatus::Active]);

        $rule->markAsTerminated();

        expect($rule->statusEnum())->toBe(RecurringTransactionRuleStatus::Terminated)
            ->and($rule->terminated_at)->not->toBeNull();
    });

    it('terminates without raising on a legacy nil grants_target_top_up', function (): void {
        $rule = RecurringTransactionRule::factory()->create(['method' => 'target', 'status' => RecurringTransactionRuleStatus::Active]);
        $rule->forceFill(['grants_target_top_up' => null])->saveQuietly();

        $rule->markAsTerminated();

        expect($rule->refresh()->statusEnum())->toBe(RecurringTransactionRuleStatus::Terminated);
    });
});

describe('#applyMinTopUpLimits', function (): void {
    it('returns the value untouched when the rule ignores paid top up limits', function (): void {
        $rule = RecurringTransactionRule::factory()->forWallet(
            ruleWallet(['paid_top_up_min_amount_cents' => 10_00, 'paid_top_up_max_amount_cents' => 20_00]),
        )->create(['ignore_paid_top_up_limits' => true]);

        expect($rule->applyMinTopUpLimits(5))->toBe('5');
    });

    it('raises the amount to the wallet minimum', function (): void {
        $rule = RecurringTransactionRule::factory()->forWallet(
            ruleWallet(['paid_top_up_min_amount_cents' => 10_00, 'paid_top_up_max_amount_cents' => 20_00]),
        )->create();

        expect($rule->applyMinTopUpLimits(5))->toBe('10.0')
            ->and($rule->applyMinTopUpLimits(15))->toBe('15')
            ->and($rule->applyMinTopUpLimits(25))->toBe('25');
    });

    it('returns the value when the wallet has no minimum', function (): void {
        $rule = RecurringTransactionRule::factory()->forWallet(ruleWallet())->create();

        expect($rule->applyMinTopUpLimits(5))->toBe('5');
    });
});

describe('#applyMaxTopUpLimits', function (): void {
    it('caps the amount at the wallet maximum', function (): void {
        $rule = RecurringTransactionRule::factory()->forWallet(
            ruleWallet(['paid_top_up_min_amount_cents' => 10_00, 'paid_top_up_max_amount_cents' => 20_00]),
        )->create();

        expect($rule->applyMaxTopUpLimits(25))->toBe('20.0')
            ->and($rule->applyMaxTopUpLimits(15))->toBe('15');
    });
});

describe('#computeGrantedCredits', function (): void {
    it('returns the rule granted credits for fixed rules', function (): void {
        $rule = RecurringTransactionRule::factory()->create(['method' => 'fixed', 'granted_credits' => '10.00']);

        expect($rule->computeGrantedCredits())->toBe('10.00000');
    });

    it('returns zero for paid target rules', function (): void {
        $rule = RecurringTransactionRule::factory()->create(['method' => 'target']);

        expect($rule->computeGrantedCredits())->toBe('0.0');
    });

    it('returns zero for legacy nil grants_target_top_up target rules', function (): void {
        $rule = RecurringTransactionRule::factory()->make(['method' => 'target']);
        $rule->grants_target_top_up = null;

        expect($rule->computeGrantedCredits())->toBe('0.0');
    });

    it('returns the raw gap for granting target rules, bypassing the paid_top_up_min limit', function (): void {
        $wallet = ruleWallet(['rate_amount' => '0.5', 'paid_top_up_min_amount_cents' => 25_00, 'credits_ongoing_balance' => 100.0]);
        $rule = RecurringTransactionRule::factory()->forWallet($wallet)->make([
            'method' => 'target',
            'grants_target_top_up' => true,
            'target_ongoing_balance' => 101.0,
        ]);

        expect($rule->computeGrantedCredits())->toBe('1.0')
            ->and($rule->computePaidCredits(ongoingBalance: '100.0'))->toBe('0.0');
    });

    it('grants nothing when the balance already exceeds the target', function (): void {
        $wallet = ruleWallet(['rate_amount' => '0.5', 'credits_ongoing_balance' => 150.0]);
        $rule = RecurringTransactionRule::factory()->forWallet($wallet)->make([
            'method' => 'target',
            'grants_target_top_up' => true,
            'target_ongoing_balance' => 100.0,
        ]);

        expect($rule->computeGrantedCredits())->toBe('0.0');
    });
});

describe('#computePaidCredits', function (): void {
    it('returns the rule paid credits for fixed interval rules', function (): void {
        $rule = RecurringTransactionRule::factory()->forWallet(ruleWallet(['rate_amount' => '0.5', 'paid_top_up_min_amount_cents' => 25_00]))
            ->make(['method' => 'fixed', 'target_ongoing_balance' => 100]);

        expect($rule->computePaidCredits(ongoingBalance: 100.0))->toBe('10.00000');
    });

    describe('threshold trigger', function (): void {
        function thresholdRule(Wallet $wallet, array $overrides = []): RecurringTransactionRule
        {
            return RecurringTransactionRule::factory()->forWallet($wallet)->make(ruleAttributes(array_merge([
                'trigger' => 'threshold',
                'threshold_credits' => 10.0,
                'paid_credits' => 50.0,
            ], $overrides)));
        }

        it('returns the whole gap rounded up to a multiple of paid credits', function (): void {
            $wallet = ruleWallet(['rate_amount' => '1.0']);
            $rule = thresholdRule($wallet);

            expect($rule->computePaidCredits(ongoingBalance: -429.8))->toBe('450.00000');
        });

        it('tops up past the threshold instead of landing on it', function (): void {
            $wallet = ruleWallet(['rate_amount' => '1.0']);
            $rule = thresholdRule($wallet);

            expect($rule->computePaidCredits(ongoingBalance: -50.0))->toBe('100.00000');
        });

        it('rounds up even when the gap divides exactly by the top-up amount', function (): void {
            $wallet = ruleWallet(['rate_amount' => '1.0']);
            $rule = thresholdRule($wallet);

            expect($rule->computePaidCredits(ongoingBalance: -100.0))->toBe('150.00000');
        });

        it('counts pending credits towards the gap', function (): void {
            $wallet = ruleWallet(['rate_amount' => '1.0']);
            $rule = thresholdRule($wallet, ['granted_credits' => 0.0]);

            expect($rule->computePaidCredits(ongoingBalance: -429.8, pendingCredits: 50.0))->toBe('400.00000');
        });

        it('counts the granted credits towards the gap', function (): void {
            $wallet = ruleWallet(['rate_amount' => '1.0']);
            $rule = thresholdRule($wallet, ['granted_credits' => 50.0]);

            expect($rule->computePaidCredits(ongoingBalance: -429.8))->toBe('400.00000');
        });

        it('caps the gap top-up at the wallet maximum', function (): void {
            $wallet = ruleWallet(['rate_amount' => '1.0', 'paid_top_up_max_amount_cents' => 100_00]);
            $rule = thresholdRule($wallet);

            expect($rule->computePaidCredits(ongoingBalance: -429.8))->toBe('100.0');
        });

        it('returns the whole gap when the rule ignores the top-up limits', function (): void {
            $wallet = ruleWallet(['rate_amount' => '1.0', 'paid_top_up_max_amount_cents' => 100_00]);
            $rule = thresholdRule($wallet, ['ignore_paid_top_up_limits' => true]);

            expect($rule->computePaidCredits(ongoingBalance: -429.8))->toBe('450.00000');
        });

        it('returns the configured amount for interval-triggered fixed rules', function (): void {
            $wallet = ruleWallet(['rate_amount' => '1.0']);
            $rule = thresholdRule($wallet, ['trigger' => 'interval']);

            expect($rule->computePaidCredits(ongoingBalance: -429.8))->toBe('50.00000');
        });

        it('returns zero when the rule pays nothing', function (): void {
            $wallet = ruleWallet(['rate_amount' => '1.0']);
            $rule = thresholdRule($wallet, ['paid_credits' => 0.0]);

            expect($rule->computePaidCredits(ongoingBalance: -429.8))->toBe('0.00000');
        });
    });

    describe('target method', function (): void {
        it('returns zero when the ongoing balance reaches the target', function (): void {
            $rule = RecurringTransactionRule::factory()->forWallet(ruleWallet(['rate_amount' => '0.5', 'paid_top_up_min_amount_cents' => 25_00]))
                ->make(['method' => 'target', 'target_ongoing_balance' => 99.0]);

            expect($rule->computePaidCredits(ongoingBalance: 100.0))->toBe('0.0');
        });

        it('returns zero when the ongoing balance equals the target', function (): void {
            $rule = RecurringTransactionRule::factory()->forWallet(ruleWallet(['rate_amount' => '0.5', 'paid_top_up_min_amount_cents' => 25_00]))
                ->make(['method' => 'target', 'target_ongoing_balance' => 100.0]);

            expect($rule->computePaidCredits(ongoingBalance: 100.0))->toBe('0.0');
        });

        it('returns the gap with the wallet minimum applied', function (): void {
            $rule = RecurringTransactionRule::factory()->forWallet(ruleWallet(['rate_amount' => '0.5', 'paid_top_up_min_amount_cents' => 25_00]))
                ->make(['method' => 'target', 'target_ongoing_balance' => 101.0]);

            // min amount 25 x 2 because of wallet's rate 0.5
            expect($rule->computePaidCredits(ongoingBalance: 100.0))->toBe('50.0');
        });

        it('behaves like a paid target rule for legacy nil grants_target_top_up', function (): void {
            $rule = RecurringTransactionRule::factory()->forWallet(ruleWallet(['rate_amount' => '0.5', 'paid_top_up_min_amount_cents' => 25_00]))
                ->make(['method' => 'target', 'target_ongoing_balance' => 101.0]);
            $rule->grants_target_top_up = null;

            expect($rule->computePaidCredits(ongoingBalance: 100.0))->toBe('50.0');
        });

        it('returns zero when grants_target_top_up is true', function (): void {
            $rule = RecurringTransactionRule::factory()->forWallet(ruleWallet(['rate_amount' => '0.5', 'paid_top_up_min_amount_cents' => 25_00]))
                ->make(['method' => 'target', 'grants_target_top_up' => true, 'target_ongoing_balance' => 101.0]);

            expect($rule->computePaidCredits(ongoingBalance: 100.0))->toBe('0.0');
        });
    });
});
