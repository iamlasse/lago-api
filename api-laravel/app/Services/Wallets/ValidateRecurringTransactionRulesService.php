<?php

declare(strict_types=1);

namespace App\Services\Wallets;

use App\Services\WalletTransactions\BaseValidator;
use App\Services\Wallets\RecurringTransactionRules\ValidateService;

use function count;
use function array_key_exists;

/**
 * Port of Rails' Wallets::ValidateRecurringTransactionRulesService
 * (app/services/wallets/validate_recurring_transaction_rules_service.rb) —
 * at most one recurring_transaction_rules entry, itself shape-valid.
 */
class ValidateRecurringTransactionRulesService extends BaseValidator
{
    public function __construct(
        \App\Services\BaseResult $result,
        protected array $args,
    ) {
        parent::__construct($result);
    }

    public function valid(): bool
    {
        if (! array_key_exists('recurring_transaction_rules', $this->args) || $this->args['recurring_transaction_rules'] === null) {
            return true;
        }

        $rules = (array) $this->args['recurring_transaction_rules'];

        $this->validTransactionRulesNumber($rules);
        $this->validTransactionRules($rules);

        if ($this->errors()) {
            $this->result->validationFailure($this->messages());

            return false;
        }

        return true;
    }

    /** @param  list<mixed>  $rules */
    private function validTransactionRulesNumber(array $rules): void
    {
        $count = count($rules);

        if ($count === 0 || $count === 1) {
            return;
        }

        $this->addError('recurring_transaction_rules', 'invalid_number_of_recurring_rules');
    }

    /** @param  list<mixed>  $rules */
    private function validTransactionRules(array $rules): void
    {
        if ($rules === []) {
            return;
        }

        /** @var array<string, mixed> $first */
        $first = (array) $rules[0];

        if (! ValidateService::call(params: $first)) {
            $this->addError('recurring_transaction_rules', 'invalid_recurring_rule');
        }
    }
}
