<?php

declare(strict_types=1);

use App\Services\Wallets\RecurringTransactionRules\ValidateService;

/**
 * Port of Rails'
 * spec/services/wallets/recurring_transaction_rules/validate_service_spec.rb.
 */
uses()->group('ledger:svc:Wallets.RecurringTransactionRules.ValidateService');

function ruleValidate(array $params): bool
{
    return ValidateService::call(params: $params);
}

it('accepts a valid interval rule', function (): void {
    expect(ruleValidate(['trigger' => 'interval', 'interval' => 'weekly']))->toBeTrue();
});

it('rejects an invalid interval', function (): void {
    expect(ruleValidate(['trigger' => 'interval', 'interval' => 'invalid']))->toBeFalse();
});

it('rejects an invalid threshold', function (): void {
    expect(ruleValidate(['trigger' => 'threshold', 'threshold_credits' => 'invalid']))->toBeFalse();
});

it('rejects an invalid target_ongoing_balance on target rules', function (): void {
    expect(ruleValidate([
        'method' => 'target',
        'trigger' => 'interval',
        'interval' => 'weekly',
        'target_ongoing_balance' => 'invalid',
    ]))->toBeFalse();
});

it('accepts valid transaction_metadata', function (): void {
    expect(ruleValidate([
        'trigger' => 'interval',
        'interval' => 'weekly',
        'transaction_metadata' => [['key' => 'valid_key', 'value' => 'invalid_value']],
    ]))->toBeTrue();
});

it('rejects non-list transaction_metadata', function (): void {
    expect(ruleValidate([
        'trigger' => 'interval',
        'interval' => 'weekly',
        'transaction_metadata' => ['key' => 'valid_key', 'value' => 'invalid_value'],
    ]))->toBeFalse();
});

it('rejects invalid credits', function (): void {
    expect(ruleValidate([
        'trigger' => 'interval',
        'interval' => 'weekly',
        'paid_credits' => 'invalid',
    ]))->toBeFalse();
});

describe('valid_grants_target_top_up', function (): void {
    it('accepts true when the method is target', function (): void {
        expect(ruleValidate([
            'method' => 'target',
            'trigger' => 'interval',
            'interval' => 'weekly',
            'target_ongoing_balance' => '100',
            'grants_target_top_up' => true,
        ]))->toBeTrue();
    });

    it('rejects true when the method is not target', function (): void {
        expect(ruleValidate([
            'method' => 'fixed',
            'trigger' => 'interval',
            'interval' => 'weekly',
            'grants_target_top_up' => true,
        ]))->toBeFalse();
    });

    it('rejects false when the method is not target', function (): void {
        expect(ruleValidate([
            'method' => 'fixed',
            'trigger' => 'interval',
            'interval' => 'weekly',
            'grants_target_top_up' => false,
        ]))->toBeFalse();
    });

    it('accepts an omitted grants_target_top_up', function (): void {
        expect(ruleValidate([
            'method' => 'fixed',
            'trigger' => 'interval',
            'interval' => 'weekly',
        ]))->toBeTrue();
    });

    it('accepts a nil grants_target_top_up', function (): void {
        expect(ruleValidate([
            'method' => 'fixed',
            'trigger' => 'interval',
            'interval' => 'weekly',
            'grants_target_top_up' => null,
        ]))->toBeTrue();
    });

    it('rejects grants_target_top_up when the method is omitted from a partial update payload', function (): void {
        expect(ruleValidate([
            'trigger' => 'interval',
            'interval' => 'weekly',
            'grants_target_top_up' => true,
        ]))->toBeFalse();
    });

    it('accepts the string "true" when the method is target', function (): void {
        expect(ruleValidate([
            'method' => 'target',
            'trigger' => 'interval',
            'interval' => 'weekly',
            'target_ongoing_balance' => '100',
            'grants_target_top_up' => 'true',
        ]))->toBeTrue();
    });

    it('rejects the string "true" when the method is not target', function (): void {
        expect(ruleValidate([
            'method' => 'fixed',
            'trigger' => 'interval',
            'interval' => 'weekly',
            'grants_target_top_up' => 'true',
        ]))->toBeFalse();
    });

    it('rejects the string "false" when the method is not target', function (): void {
        expect(ruleValidate([
            'method' => 'fixed',
            'trigger' => 'interval',
            'interval' => 'weekly',
            'grants_target_top_up' => 'false',
        ]))->toBeFalse();
    });
});

describe('valid_target_above_threshold', function (): void {
    it('rejects a target below the threshold', function (): void {
        expect(ruleValidate([
            'method' => 'target',
            'trigger' => 'threshold',
            'target_ongoing_balance' => '50',
            'threshold_credits' => '100',
        ]))->toBeFalse();
    });

    it('accepts a target equal to the threshold', function (): void {
        expect(ruleValidate([
            'method' => 'target',
            'trigger' => 'threshold',
            'target_ongoing_balance' => '100',
            'threshold_credits' => '100',
        ]))->toBeTrue();
    });

    it('accepts a target above the threshold', function (): void {
        expect(ruleValidate([
            'method' => 'target',
            'trigger' => 'threshold',
            'target_ongoing_balance' => '150',
            'threshold_credits' => '100',
        ]))->toBeTrue();
    });

    it('ignores the threshold comparison when the trigger is interval', function (): void {
        expect(ruleValidate([
            'method' => 'target',
            'trigger' => 'interval',
            'interval' => 'weekly',
            'target_ongoing_balance' => '50',
            'threshold_credits' => '100',
        ]))->toBeTrue();
    });
});

describe('valid_expiration_at', function (): void {
    it('accepts a blank expiration_at', function (): void {
        expect(ruleValidate(['trigger' => 'interval', 'interval' => 'weekly', 'expiration_at' => null]))->toBeTrue();
    });

    it('rejects an invalid format', function (): void {
        expect(ruleValidate(['trigger' => 'interval', 'interval' => 'weekly', 'expiration_at' => 'invalid-date']))->toBeFalse();
    });

    it('rejects a past date', function (): void {
        expect(ruleValidate([
            'trigger' => 'interval',
            'interval' => 'weekly',
            'expiration_at' => now()->subHour()->toIso8601String(),
        ]))->toBeFalse();
    });

    it('accepts a valid future date', function (): void {
        expect(ruleValidate([
            'trigger' => 'interval',
            'interval' => 'weekly',
            'expiration_at' => now()->addHour()->toIso8601String(),
        ]))->toBeTrue();
    });
});
