<?php

declare(strict_types=1);

require_once __DIR__.'/../WalletsTestHelpers.php';

use App\Models\Wallet;
use App\Models\RecurringTransactionRule;
use App\Enums\RecurringTransactionRuleStatus;
use App\Services\Wallets\RecurringTransactionRules\TerminateService;

/**
 * Port of Rails'
 * spec/services/wallets/recurring_transaction_rules/terminate_service_spec.rb.
 */
uses()->group('ledger:svc:Wallets.RecurringTransactionRules.TerminateService');

it('terminates the recurring transaction rule', function (): void {
    $wallet = Wallet::factory()->create();
    $rule = RecurringTransactionRule::factory()->forWallet($wallet)->create([
        'status' => RecurringTransactionRuleStatus::Active,
        'expiration_at' => now()->subDays(40),
    ]);

    $result = TerminateService::call(recurringTransactionRule: $rule);

    expect($result->success())->toBeTrue()
        ->and($rule->refresh()->statusEnum())->toBe(RecurringTransactionRuleStatus::Terminated);
});

it('does not change the termination date when already terminated', function (): void {
    $wallet = Wallet::factory()->create();
    $rule = RecurringTransactionRule::factory()->forWallet($wallet)->create([
        'status' => RecurringTransactionRuleStatus::Active,
        'expiration_at' => now()->subDays(40),
    ]);
    $rule->markAsTerminated();

    $terminatedAt = $rule->terminated_at;

    $result = TerminateService::call(recurringTransactionRule: $rule);

    expect($result->success())->toBeTrue()
        ->and($rule->refresh()->statusEnum())->toBe(RecurringTransactionRuleStatus::Terminated)
        ->and($rule->terminated_at)->toEqual($terminatedAt);
});

it('answers a not-found failure for a missing rule', function (): void {
    $result = TerminateService::call(recurringTransactionRule: null);

    expect($result->success())->toBeFalse();
});
