<?php

declare(strict_types=1);

require_once __DIR__.'/../WalletsTestHelpers.php';

use App\Models\Wallet;
use App\Models\PaymentMethod;
use App\Support\CurrentContext;
use App\Models\InvoiceCustomSection;
use Illuminate\Support\Facades\Date;
use App\Services\Wallets\RecurringTransactionRules\CreateService;

/**
 * Port of Rails'
 * spec/services/wallets/recurring_transaction_rules/create_service_spec.rb
 * (the BillingObjectConnections contexts wait on the connections slice).
 */
uses()->group('ledger:svc:Wallets.RecurringTransactionRules.CreateService');

beforeEach(function (): void {
    Date::setTestNow();
    CurrentContext::reset();
    CurrentContext::$source = 'api';
    config(['lago.license' => 'premium-license-token']);
});

function ruleCreateWallet(): Wallet
{
    return Wallet::factory()->create(['paid_top_up_min_amount_cents' => 15_00]);
}

function ruleCreateArgs(Wallet $wallet, array $ruleParams, array $walletOverrides = []): array
{
    return array_merge([
        'paid_credits' => '100.0',
        'granted_credits' => '50.0',
        'recurring_transaction_rules' => [$ruleParams],
    ], $walletOverrides);
}

function createRule(Wallet $wallet, array $ruleParams, array $walletOverrides = [])
{
    return CreateService::call(wallet: $wallet, walletParams: ruleCreateArgs($wallet, $ruleParams, $walletOverrides));
}

it('does not create any recurring transaction rule when freemium', function (): void {
    config(['lago.license' => null]);
    $wallet = ruleCreateWallet();

    $result = createRule($wallet, [
        'interval' => 'monthly',
        'method' => 'target',
        'paid_credits' => '10.0',
        'granted_credits' => '5.0',
        'started_at' => '2024-05-30T12:48:26Z',
        'target_ongoing_balance' => '100.0',
        'trigger' => 'interval',
        'ignore_paid_top_up_limits' => 'true',
    ]);

    expect($result->success())->toBeTrue()
        ->and($wallet->recurringTransactionRules()->count())->toBe(0);
})->group('ledger:svc:Wallets.RecurringTransactionRules.CreateService');

it('creates a rule with the expected attributes', function (): void {
    $wallet = ruleCreateWallet();

    $result = createRule($wallet, [
        'interval' => 'monthly',
        'method' => 'target',
        'paid_credits' => '10.0',
        'granted_credits' => '5.0',
        'started_at' => '2024-05-30T12:48:26Z',
        'target_ongoing_balance' => '100.0',
        'trigger' => 'interval',
        'ignore_paid_top_up_limits' => 'true',
    ]);

    expect($result->success())->toBeTrue();

    $rule = $wallet->recurringTransactionRules()->first();

    expect($rule->granted_credits)->toBe('5.00000')
        ->and($rule->intervalEnum()?->label())->toBe('monthly')
        ->and($rule->methodEnum()?->label())->toBe('target')
        ->and($rule->paid_credits)->toBe('10.00000')
        ->and($rule->started_at->toIso8601String())->toBe('2024-05-30T12:48:26+00:00')
        ->and($rule->target_ongoing_balance)->toBe('100.00000')
        ->and($rule->threshold_credits)->toBe('0.00000')
        ->and($rule->triggerEnum()?->label())->toBe('interval')
        ->and($rule->invoice_requires_successful_payment)->toBeFalse()
        ->and($rule->ignore_paid_top_up_limits)->toBeTrue();
})->group('ledger:svc:Wallets.RecurringTransactionRules.CreateService');

it('creates a rule with the purchase order number from the rule params', function (): void {
    $wallet = ruleCreateWallet();

    createRule($wallet, [
        'interval' => 'monthly',
        'method' => 'target',
        'paid_credits' => '10.0',
        'granted_credits' => '5.0',
        'started_at' => '2024-05-30T12:48:26Z',
        'target_ongoing_balance' => '100.0',
        'trigger' => 'interval',
        'ignore_paid_top_up_limits' => 'true',
        'purchase_order_number' => 'PO-RULE-123',
    ]);

    expect($wallet->recurringTransactionRules()->first()->purchase_order_number)->toBe('PO-RULE-123');
})->group('ledger:svc:Wallets.RecurringTransactionRules.CreateService');

it('rejects a too long purchase order number', function (): void {
    $wallet = ruleCreateWallet();

    $result = createRule($wallet, [
        'interval' => 'monthly',
        'method' => 'target',
        'paid_credits' => '10.0',
        'granted_credits' => '5.0',
        'started_at' => '2024-05-30T12:48:26Z',
        'target_ongoing_balance' => '100.0',
        'trigger' => 'interval',
        'ignore_paid_top_up_limits' => 'true',
        'purchase_order_number' => str_repeat('a', 256),
    ]);

    expect($result->success())->toBeFalse()
        ->and($wallet->recurringTransactionRules()->count())->toBe(0)
        ->and($result->getError()?->messages)->toBe(['purchase_order_number' => ['value_is_too_long']]);
})->group('ledger:svc:Wallets.RecurringTransactionRules.CreateService');

it('inherits the wallet paid and granted credits for fixed rules omitting them', function (): void {
    $wallet = ruleCreateWallet();

    createRule($wallet, ['trigger' => 'threshold', 'threshold_credits' => '1.0']);

    $rule = $wallet->recurringTransactionRules()->first();

    expect($rule->granted_credits)->toBe('50.00000')
        ->and($rule->methodEnum()?->label())->toBe('fixed')
        ->and($rule->paid_credits)->toBe('100.00000')
        ->and($rule->target_ongoing_balance)->toBeNull()
        ->and($rule->threshold_credits)->toBe('1.00000')
        ->and($rule->triggerEnum()?->label())->toBe('threshold');
})->group('ledger:svc:Wallets.RecurringTransactionRules.CreateService');

it('creates a fixed rule when the paid credits amount is aligned with wallet limits', function (): void {
    $wallet = ruleCreateWallet();

    createRule($wallet, ['trigger' => 'threshold', 'threshold_credits' => '1.0', 'paid_credits' => '15']);

    $rule = $wallet->recurringTransactionRules()->first();

    expect($rule->granted_credits)->toBe('0.00000')
        ->and($rule->methodEnum()?->label())->toBe('fixed')
        ->and($rule->paid_credits)->toBe('15.00000')
        ->and($rule->target_ongoing_balance)->toBeNull()
        ->and($rule->threshold_credits)->toBe('1.00000')
        ->and($rule->triggerEnum()?->label())->toBe('threshold');
})->group('ledger:svc:Wallets.RecurringTransactionRules.CreateService');

it('fails when the fixed paid credits amount exceeds the wallet limits', function (): void {
    $wallet = ruleCreateWallet();

    $result = createRule($wallet, ['trigger' => 'threshold', 'threshold_credits' => '1.0', 'paid_credits' => '5']);

    expect($result->success())->toBeFalse()
        ->and($wallet->recurringTransactionRules()->count())->toBe(0)
        ->and($result->getError()?->messages)->toBe(['recurring_transaction_rules' => ['invalid_recurring_rule']]);
})->group('ledger:svc:Wallets.RecurringTransactionRules.CreateService');

it('creates a fixed rule with zero paid credits', function (): void {
    $wallet = ruleCreateWallet();

    createRule($wallet, ['trigger' => 'threshold', 'threshold_credits' => '1.0', 'paid_credits' => '0']);

    $rule = $wallet->recurringTransactionRules()->first();

    expect($rule->granted_credits)->toBe('0.00000')
        ->and($rule->methodEnum()?->label())->toBe('fixed')
        ->and($rule->paid_credits)->toBe('0.00000')
        ->and($rule->target_ongoing_balance)->toBeNull()
        ->and($rule->threshold_credits)->toBe('1.00000')
        ->and($rule->triggerEnum()?->label())->toBe('threshold');
})->group('ledger:svc:Wallets.RecurringTransactionRules.CreateService');

it('creates a target rule ignoring wallet limits with grants_target_top_up defaulting to false', function (): void {
    $wallet = ruleCreateWallet();

    createRule($wallet, ['trigger' => 'threshold', 'method' => 'target', 'threshold_credits' => '1.0', 'paid_credits' => '5']);

    $rule = $wallet->recurringTransactionRules()->first();

    expect($rule->granted_credits)->toBe('0.00000')
        ->and($rule->grants_target_top_up)->toBeFalse()
        ->and($rule->methodEnum()?->label())->toBe('target')
        ->and($rule->paid_credits)->toBe('5.00000')
        ->and($rule->target_ongoing_balance)->toBeNull()
        ->and($rule->threshold_credits)->toBe('1.00000')
        ->and($rule->triggerEnum()?->label())->toBe('threshold');
})->group('ledger:svc:Wallets.RecurringTransactionRules.CreateService');

it('creates a target rule with grants_target_top_up true', function (): void {
    $wallet = ruleCreateWallet();

    createRule($wallet, ['trigger' => 'threshold', 'method' => 'target', 'threshold_credits' => '1.0', 'grants_target_top_up' => 'true']);

    expect($wallet->recurringTransactionRules()->first()->grants_target_top_up)->toBeTrue();
})->group('ledger:svc:Wallets.RecurringTransactionRules.CreateService');

it('creates a target rule with grants_target_top_up false', function (): void {
    $wallet = ruleCreateWallet();

    createRule($wallet, ['trigger' => 'threshold', 'method' => 'target', 'threshold_credits' => '1.0', 'grants_target_top_up' => 'false']);

    expect($wallet->recurringTransactionRules()->first()->grants_target_top_up)->toBeFalse();
})->group('ledger:svc:Wallets.RecurringTransactionRules.CreateService');

it('follows an explicit invoice_requires_successful_payment', function (): void {
    $wallet = ruleCreateWallet();

    createRule($wallet, ['trigger' => 'threshold', 'threshold_credits' => '1.0', 'invoice_requires_successful_payment' => true]);

    expect($wallet->recurringTransactionRules()->first()->invoice_requires_successful_payment)->toBeTrue();
})->group('ledger:svc:Wallets.RecurringTransactionRules.CreateService');

it('follows the wallet invoice_requires_successful_payment when the rule omits it', function (): void {
    $wallet = Wallet::factory()->create(['invoice_requires_successful_payment' => true]);

    createRule($wallet, ['trigger' => 'threshold', 'threshold_credits' => '1.0']);

    expect($wallet->recurringTransactionRules()->first()->invoice_requires_successful_payment)->toBeTrue();
})->group('ledger:svc:Wallets.RecurringTransactionRules.CreateService');

it('stores the transaction metadata', function (): void {
    $wallet = ruleCreateWallet();

    createRule($wallet, [
        'trigger' => 'threshold',
        'threshold_credits' => '1.0',
        'transaction_metadata' => [['key' => 'valid_value', 'value' => 'also_valid']],
    ]);

    expect($wallet->recurringTransactionRules()->first()->transaction_metadata)
        ->toBe([['key' => 'valid_value', 'value' => 'also_valid']]);
})->group('ledger:svc:Wallets.RecurringTransactionRules.CreateService');

it('creates a rule with the correct expiration_at', function (): void {
    $wallet = ruleCreateWallet();
    $expirationAt = now()->addYear()->toIso8601String();

    createRule($wallet, ['trigger' => 'threshold', 'threshold_credits' => '1.0', 'expiration_at' => $expirationAt]);

    expect($wallet->recurringTransactionRules()->first()->expiration_at->toIso8601String())->toBe($expirationAt);
})->group('ledger:svc:Wallets.RecurringTransactionRules.CreateService');

foreach ([
    'Custom Top-up Name' => 'Custom Top-up Name',
    '' => null,
    '   ' => null,
    null => null,
] as $transactionName => $expectedTransactionName) {
    it('creates a rule with transaction_name '.var_export($transactionName, true), function () use ($transactionName, $expectedTransactionName): void {
        $wallet = ruleCreateWallet();

        createRule($wallet, [
            'trigger' => 'threshold',
            'threshold_credits' => '1.0',
            'transaction_name' => $transactionName,
        ]);

        expect($wallet->recurringTransactionRules()->first()->transaction_name)->toBe($expectedTransactionName);
    })->group('ledger:svc:Wallets.RecurringTransactionRules.CreateService');
}

describe('payment method', function (): void {
    it('attaches a valid payment method', function (): void {
        $wallet = ruleCreateWallet();
        $paymentMethod = PaymentMethod::factory()->create([
            'organization_id' => $wallet->organization_id,
            'customer_id' => $wallet->customer_id,
        ]);

        $result = createRule($wallet, [
            'trigger' => 'threshold',
            'threshold_credits' => '1.0',
            'payment_method' => ['payment_method_id' => $paymentMethod->id, 'payment_method_type' => 'provider'],
        ]);

        expect($result->success())->toBeTrue()
            ->and($wallet->recurringTransactionRules()->first())
            ->payment_method_id->toBe($paymentMethod->id)
            ->payment_method_type->toBe('provider');
    });

    it('keeps a null payment method id', function (): void {
        $wallet = ruleCreateWallet();

        $result = createRule($wallet, [
            'trigger' => 'threshold',
            'threshold_credits' => '1.0',
            'payment_method' => ['payment_method_id' => null, 'payment_method_type' => 'provider'],
        ]);

        expect($result->success())->toBeTrue()
            ->and($wallet->recurringTransactionRules()->first()->payment_method_id)->toBeNull();
    });

    // TODO(port): Rails also answers invalid_payment_method for unknown ids
    // and types — PaymentMethods::ValidateService is not ported yet.
});

describe('invoice_custom_section', function (): void {
    it('creates the rule skipping sections', function (): void {
        $wallet = ruleCreateWallet();

        createRule($wallet, [
            'interval' => 'monthly',
            'method' => 'target',
            'started_at' => '2024-05-30T12:48:26Z',
            'target_ongoing_balance' => '100.0',
            'trigger' => 'interval',
            'invoice_custom_section' => ['skip_invoice_custom_sections' => true],
        ]);

        expect($wallet->recurringTransactionRules()->first()->skip_invoice_custom_sections)->toBeTrue();
    });

    it('attaches sections by code', function (): void {
        $wallet = ruleCreateWallet();
        $section1 = InvoiceCustomSection::factory()->create(['organization_id' => $wallet->organization_id, 'code' => 'section_code_1']);
        $section2 = InvoiceCustomSection::factory()->create(['organization_id' => $wallet->organization_id, 'code' => 'section_code_2']);

        createRule($wallet, [
            'interval' => 'monthly',
            'method' => 'target',
            'started_at' => '2024-05-30T12:48:26Z',
            'target_ongoing_balance' => '100.0',
            'trigger' => 'interval',
            'invoice_custom_section' => ['invoice_custom_section_codes' => ['section_code_1', 'section_code_2']],
        ]);

        $sections = $wallet->recurringTransactionRules()->first()->appliedInvoiceCustomSections;

        expect($sections->count())->toBe(2)
            ->and($sections->pluck('invoice_custom_section_id')->all())
            ->toEqualCanonicalizing([$section1->id, $section2->id]);
    });
});
