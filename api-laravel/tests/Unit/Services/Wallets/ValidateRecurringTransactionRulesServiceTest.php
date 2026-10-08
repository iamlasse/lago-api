<?php

declare(strict_types=1);

use App\Services\BaseResult;
use App\Services\Wallets\ValidateRecurringTransactionRulesService;

/**
 * Port of Rails'
 * spec/services/wallets/validate_recurring_transaction_rules_service_spec.rb.
 */
uses()->group('ledger:svc:Wallets.ValidateRecurringTransactionRulesService');

it('passes when there are no recurring transaction rules', function (): void {
    $result = BaseResult::of();

    expect((new ValidateRecurringTransactionRulesService($result, []))->valid())->toBeTrue();
});

it('rejects more than one recurring transaction rule', function (): void {
    $result = BaseResult::of();

    $rules = [
        [
            'trigger' => 'interval',
            'interval' => 'monthly',
            'paid_credits' => '105',
            'granted_credits' => '105',
        ],
        [
            'trigger' => 'threshold',
            'threshold_credits' => '1.0',
            'paid_credits' => '105',
            'granted_credits' => '105',
        ],
    ];

    expect((new ValidateRecurringTransactionRulesService($result, ['recurring_transaction_rules' => $rules]))->valid())->toBeFalse()
        ->and($result->getError()?->messages)->toBe(['recurring_transaction_rules' => ['invalid_number_of_recurring_rules']]);
});
