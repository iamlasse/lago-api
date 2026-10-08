<?php

declare(strict_types=1);

use App\Models\Wallet;
use App\Models\RecurringTransactionRule;
use App\Enums\RecurringTransactionRuleStatus;
use App\Jobs\Clock\TerminateRecurringTransactionRulesJob;

/**
 * Port of Rails' spec/jobs/clock/terminate_recurring_transaction_rules_job_spec.rb.
 */
uses()->group('ledger:job:Clock.TerminateRecurringTransactionRulesJob');

it('terminates the expired recurring transaction rules', function (): void {
    $wallet = Wallet::factory()->create();

    $toExpire = RecurringTransactionRule::factory()->forWallet($wallet)->create([
        'status' => RecurringTransactionRuleStatus::Active,
        'expiration_at' => now()->subDays(40),
    ]);

    $toKeep = RecurringTransactionRule::factory()->forWallet($wallet)->create([
        'status' => RecurringTransactionRuleStatus::Active,
        'expiration_at' => now()->addDays(40),
    ]);

    (new TerminateRecurringTransactionRulesJob)->handle();

    expect($toExpire->refresh()->statusEnum())->toBe(RecurringTransactionRuleStatus::Terminated)
        ->and($toKeep->refresh()->statusEnum())->toBe(RecurringTransactionRuleStatus::Active);
});
