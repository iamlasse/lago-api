<?php

declare(strict_types=1);

require_once __DIR__.'/../WalletsTestHelpers.php';

use App\Models\Wallet;
use App\Support\CurrentContext;
use Illuminate\Support\Facades\Date;
use App\Models\RecurringTransactionRule;
use App\Services\Wallets\RecurringTransactionRules\UpdateService;

/**
 * Port of Rails'
 * spec/services/wallets/recurring_transaction_rules/update_service_spec.rb
 * (the BillingObjectConnections contexts wait on the connections slice).
 */
uses()->group('ledger:svc:Wallets.RecurringTransactionRules.UpdateService');

beforeEach(function (): void {
    Date::setTestNow();
    CurrentContext::reset();
    CurrentContext::$source = 'api';
});

function ruleUpdate(RecurringTransactionRule $rule, array $params): mixed
{
    return UpdateService::call(wallet: $rule->wallet, params: $params);
}

function activeRuleOf(Wallet $wallet): RecurringTransactionRule
{
    /** @var RecurringTransactionRule $first */
    return $wallet->recurringTransactionRules()->active()->first();
}

it('updates an existing active recurring transaction rule', function (): void {
    $wallet = Wallet::factory()->create();
    $rule = RecurringTransactionRule::factory()->forWallet($wallet)->create();

    $result = ruleUpdate($rule, [[
        'lago_id' => $rule->id,
        'trigger' => 'interval',
        'interval' => 'weekly',
        'paid_credits' => '105',
        'granted_credits' => '105',
        'started_at' => '2024-05-30T12:48:26Z',
        'transaction_metadata' => [],
        'invoice_custom_section' => ['skip_invoice_custom_sections' => true],
    ]]);

    expect($wallet->recurringTransactionRules()->count())->toBe(1);

    $updated = activeRuleOf($wallet);

    expect($updated->granted_credits)->toBe('105.00000')
        ->and($updated->id)->toBe($rule->id)
        ->and($updated->intervalEnum()?->label())->toBe('weekly')
        ->and($updated->methodEnum()?->label())->toBe('fixed')
        ->and($updated->paid_credits)->toBe('105.00000')
        ->and($updated->started_at->toIso8601String())->toBe('2024-05-30T12:48:26+00:00')
        ->and($updated->threshold_credits)->toBe('0.00000')
        ->and($updated->triggerEnum()?->label())->toBe('interval')
        ->and($updated->skip_invoice_custom_sections)->toBeTrue()
        ->and($result->wallet->id)->toBe($wallet->id);
});

it('updates the existing rule purchase order number', function (): void {
    $wallet = Wallet::factory()->create();
    $rule = RecurringTransactionRule::factory()->forWallet($wallet)->create();

    ruleUpdate($rule, [[
        'lago_id' => $rule->id,
        'trigger' => 'interval',
        'interval' => 'weekly',
        'paid_credits' => '105',
        'granted_credits' => '105',
        'purchase_order_number' => 'PO-RULE-123',
    ]]);

    expect(activeRuleOf($wallet)->purchase_order_number)->toBe('PO-RULE-123');
});

it('rejects a too long purchase order number', function (): void {
    $wallet = Wallet::factory()->create();
    $rule = RecurringTransactionRule::factory()->forWallet($wallet)->create();

    $result = ruleUpdate($rule, [[
        'lago_id' => $rule->id,
        'trigger' => 'interval',
        'interval' => 'weekly',
        'paid_credits' => '105',
        'granted_credits' => '105',
        'purchase_order_number' => str_repeat('a', 256),
    ]]);

    expect($result->success())->toBeFalse()
        ->and($result->getError()?->messages)->toBe(['purchase_order_number' => ['value_is_too_long']]);
});

it('does not update inactive rules and creates a new one', function (): void {
    $wallet = Wallet::factory()->create();
    $rule = RecurringTransactionRule::factory()->forWallet($wallet)->create();
    $rule->markAsTerminated();

    ruleUpdate($rule, [[
        'lago_id' => $rule->id,
        'trigger' => 'interval',
        'interval' => 'weekly',
        'paid_credits' => '105',
        'granted_credits' => '105',
        'invoice_custom_section' => ['skip_invoice_custom_sections' => true],
    ]]);

    expect($wallet->recurringTransactionRules()->count())->toBe(2)
        ->and($wallet->recurringTransactionRules()->active()->count())->toBe(1);

    $activeRule = activeRuleOf($wallet);

    expect($activeRule->granted_credits)->toBe('105.00000')
        ->and($activeRule->intervalEnum()?->label())->toBe('weekly')
        ->and($activeRule->methodEnum()?->label())->toBe('fixed')
        ->and($activeRule->paid_credits)->toBe('105.00000')
        ->and($activeRule->triggerEnum()?->label())->toBe('interval')
        ->and($activeRule->skip_invoice_custom_sections)->toBeTrue();
});

it('creates a new rule and terminates the existing one when the payload rule has no id', function (): void {
    $wallet = Wallet::factory()->create();
    $rule = RecurringTransactionRule::factory()->forWallet($wallet)->create();

    ruleUpdate($rule, [[
        'granted_credits' => '105',
        'interval' => 'weekly',
        'method' => 'target',
        'paid_credits' => '105',
        'target_ongoing_balance' => '300',
        'trigger' => 'interval',
        'payment_method' => ['payment_method_id' => null, 'payment_method_type' => 'manual'],
    ]]);

    expect($wallet->recurringTransactionRules()->active()->count())->toBe(1)
        ->and($wallet->recurringTransactionRules()->terminated()->count())->toBe(1);

    $newRule = activeRuleOf($wallet);

    expect($newRule->granted_credits)->toBe('105.00000')
        ->and($newRule->intervalEnum()?->label())->toBe('weekly')
        ->and($newRule->methodEnum()?->label())->toBe('target')
        ->and($newRule->paid_credits)->toBe('105.00000')
        ->and($newRule->target_ongoing_balance)->toBe('300.00000')
        ->and($newRule->threshold_credits)->toBe('0.00000')
        ->and($newRule->triggerEnum()?->label())->toBe('interval')
        ->and($newRule->payment_method_id)->toBeNull()
        ->and($newRule->payment_method_type)->toBe('manual')
        ->and($newRule->id)->not->toBe($rule->id);
});

it('creates the replacement rule with the purchase order number', function (): void {
    $wallet = Wallet::factory()->create();
    $rule = RecurringTransactionRule::factory()->forWallet($wallet)->create();

    ruleUpdate($rule, [[
        'granted_credits' => '105',
        'interval' => 'weekly',
        'method' => 'target',
        'paid_credits' => '105',
        'target_ongoing_balance' => '300',
        'trigger' => 'interval',
        'purchase_order_number' => 'PO-REPLACEMENT-123',
    ]]);

    $newRule = activeRuleOf($wallet);

    expect($newRule->id)->not->toBe($rule->id)
        ->and($newRule->purchase_order_number)->toBe('PO-REPLACEMENT-123');
});

it('coerces explicitly null credit values to 0.0 instead of a not-null violation', function (): void {
    $wallet = Wallet::factory()->create();
    $rule = RecurringTransactionRule::factory()->forWallet($wallet)->create(['paid_credits' => 200, 'granted_credits' => 200]);

    ruleUpdate($rule, [[
        'lago_id' => $rule->id,
        'method' => 'target',
        'trigger' => 'interval',
        'interval' => 'monthly',
        'target_ongoing_balance' => '5300',
        'paid_credits' => null,
        'granted_credits' => null,
        'threshold_credits' => null,
    ]]);

    $updated = activeRuleOf($wallet);

    expect($updated->id)->toBe($rule->id)
        ->and($updated->methodEnum()?->label())->toBe('target')
        ->and($updated->triggerEnum()?->label())->toBe('interval')
        ->and($updated->intervalEnum()?->label())->toBe('monthly')
        ->and($updated->target_ongoing_balance)->toBe('5300.00000')
        ->and($updated->paid_credits)->toBe('0.00000')
        ->and($updated->granted_credits)->toBe('0.00000')
        ->and($updated->threshold_credits)->toBe('0.00000');
});

it('updates grants_target_top_up to grant credits', function (): void {
    $wallet = Wallet::factory()->create();
    $rule = RecurringTransactionRule::factory()->forWallet($wallet)->create([
        'method' => 'target', 'target_ongoing_balance' => 300, 'grants_target_top_up' => false,
    ]);

    ruleUpdate($rule, [[
        'lago_id' => $rule->id,
        'method' => 'target',
        'trigger' => 'interval',
        'interval' => 'weekly',
        'target_ongoing_balance' => '300',
        'grants_target_top_up' => true,
    ]]);

    $updated = activeRuleOf($wallet);

    expect($updated->id)->toBe($rule->id)
        ->and($updated->methodEnum()?->label())->toBe('target')
        ->and($updated->grants_target_top_up)->toBeTrue();
});

it('flips grants_target_top_up from true to false', function (): void {
    $wallet = Wallet::factory()->create();
    $rule = RecurringTransactionRule::factory()->forWallet($wallet)->create([
        'method' => 'target', 'target_ongoing_balance' => 300, 'grants_target_top_up' => true,
    ]);

    ruleUpdate($rule, [[
        'lago_id' => $rule->id,
        'method' => 'target',
        'trigger' => 'interval',
        'interval' => 'weekly',
        'target_ongoing_balance' => '300',
        'grants_target_top_up' => false,
    ]]);

    $updated = activeRuleOf($wallet);

    expect($updated->id)->toBe($rule->id)
        ->and($updated->methodEnum()?->label())->toBe('target')
        ->and($updated->grants_target_top_up)->toBeFalse();
});

it('clears grants_target_top_up to null when switching a granting target rule to fixed', function (): void {
    $wallet = Wallet::factory()->create();
    $rule = RecurringTransactionRule::factory()->forWallet($wallet)->create([
        'method' => 'target', 'target_ongoing_balance' => 300, 'grants_target_top_up' => true,
    ]);

    ruleUpdate($rule, [[
        'lago_id' => $rule->id,
        'method' => 'fixed',
        'trigger' => 'interval',
        'interval' => 'weekly',
        'paid_credits' => '10',
        'granted_credits' => '10',
    ]]);

    $updated = activeRuleOf($wallet);

    expect($updated->methodEnum()?->label())->toBe('fixed')
        ->and($updated->grants_target_top_up)->toBeNull();
});

it('defaults grants_target_top_up to false when switching a fixed rule to target without the flag', function (): void {
    $wallet = Wallet::factory()->create();
    $rule = RecurringTransactionRule::factory()->forWallet($wallet)->create(['method' => 'fixed']);

    ruleUpdate($rule, [[
        'lago_id' => $rule->id,
        'method' => 'target',
        'trigger' => 'interval',
        'interval' => 'weekly',
        'target_ongoing_balance' => '300',
    ]]);

    $updated = activeRuleOf($wallet);

    expect($updated->methodEnum()?->label())->toBe('target')
        ->and($updated->grants_target_top_up)->toBeFalse();
});

it('normalises a legacy nil grants_target_top_up to false without changing the method', function (): void {
    $wallet = Wallet::factory()->create();
    $rule = RecurringTransactionRule::factory()->forWallet($wallet)->create(['method' => 'target', 'target_ongoing_balance' => 300]);
    $rule->forceFill(['grants_target_top_up' => null])->saveQuietly();

    ruleUpdate($rule, [[
        'lago_id' => $rule->id,
        'trigger' => 'interval',
        'interval' => 'monthly',
        'target_ongoing_balance' => '300',
    ]]);

    $updated = activeRuleOf($wallet);

    expect($updated->methodEnum()?->label())->toBe('target')
        ->and($updated->grants_target_top_up)->toBeFalse();
});

it('terminates every rule when an empty array is sent', function (): void {
    $wallet = Wallet::factory()->create();
    $rule = RecurringTransactionRule::factory()->forWallet($wallet)->create();

    ruleUpdate($rule, []);

    expect($wallet->recurringTransactionRules()->active()->count())->toBe(0)
        ->and($wallet->recurringTransactionRules()->terminated()->count())->toBe(1);
});

it('defaults invoice_requires_successful_payment from the wallet on new rules', function (): void {
    $wallet = Wallet::factory()->create(['invoice_requires_successful_payment' => true]);

    UpdateService::call(wallet: $wallet, params: [[
        'trigger' => 'interval',
        'interval' => 'weekly',
        'paid_credits' => '10',
        'granted_credits' => '10',
    ]]);

    expect(activeRuleOf($wallet)->invoice_requires_successful_payment)->toBeTrue();
});

it('updates the rule transaction_metadata', function (): void {
    $wallet = Wallet::factory()->create();
    $rule = RecurringTransactionRule::factory()->forWallet($wallet)->create();

    $metadata = [['key' => 'key'], ['value' => 'value']];

    ruleUpdate($rule, [[
        'lago_id' => $rule->id,
        'trigger' => 'interval',
        'interval' => 'weekly',
        'paid_credits' => '105',
        'granted_credits' => '105',
        'started_at' => '2024-05-30T12:48:26Z',
        'transaction_metadata' => $metadata,
        'invoice_custom_section' => ['skip_invoice_custom_sections' => true],
    ]]);

    expect(activeRuleOf($wallet)->transaction_metadata)->toBe($metadata);
});

describe('payment_method', function (): void {
    it('updates the rule payment method', function (): void {
        $wallet = Wallet::factory()->create();
        $rule = RecurringTransactionRule::factory()->forWallet($wallet)->create();
        $paymentMethod = App\Models\PaymentMethod::factory()->create([
            'organization_id' => $wallet->organization_id,
            'customer_id' => $wallet->customer_id,
        ]);

        ruleUpdate($rule, [[
            'lago_id' => $rule->id,
            'trigger' => 'interval',
            'interval' => 'weekly',
            'paid_credits' => '105',
            'granted_credits' => '105',
            'started_at' => '2024-05-30T12:48:26Z',
            'payment_method' => ['payment_method_id' => $paymentMethod->id, 'payment_method_type' => 'provider'],
        ]]);

        $updated = activeRuleOf($wallet);

        expect($updated->payment_method_id)->toBe($paymentMethod->id)
            ->and($updated->payment_method_type)->toBe('provider');
    });

    it('removes an already attached payment method', function (): void {
        $wallet = Wallet::factory()->create();
        $paymentMethod = App\Models\PaymentMethod::factory()->create([
            'organization_id' => $wallet->organization_id,
            'customer_id' => $wallet->customer_id,
        ]);
        $rule = RecurringTransactionRule::factory()->forWallet($wallet)->create([
            'payment_method_id' => $paymentMethod->id, 'payment_method_type' => 'provider',
        ]);

        ruleUpdate($rule, [[
            'lago_id' => $rule->id,
            'trigger' => 'interval',
            'interval' => 'weekly',
            'paid_credits' => '105',
            'granted_credits' => '105',
            'payment_method' => ['payment_method_id' => null, 'payment_method_type' => 'provider'],
        ]]);

        $updated = activeRuleOf($wallet);

        expect($updated->payment_method_id)->toBeNull()
            ->and($updated->payment_method_type)->toBe('provider');
    });

    // TODO(port): Rails also answers invalid_payment_method for unknown ids
    // and types — PaymentMethods::ValidateService is not ported yet.
});

foreach ([
    'Updated Transaction Name' => 'Updated Transaction Name',
    '' => null,
    '   ' => null,
    null => null,
] as $transactionName => $expectedTransactionName) {
    it('updates the rule transaction_name from '.var_export($transactionName, true), function () use ($transactionName, $expectedTransactionName): void {
        $wallet = Wallet::factory()->create();
        $rule = RecurringTransactionRule::factory()->forWallet($wallet)->create();

        ruleUpdate($rule, [[
            'lago_id' => $rule->id,
            'trigger' => 'interval',
            'interval' => 'weekly',
            'paid_credits' => '105',
            'granted_credits' => '105',
            'transaction_name' => $transactionName,
        ]]);

        expect(activeRuleOf($wallet)->transaction_name)->toBe($expectedTransactionName);
    });
}
