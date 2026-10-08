<?php

declare(strict_types=1);

namespace App\Services\Wallets\RecurringTransactionRules;

use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\RecurringTransactionRule;

/**
 * Port of Rails' Wallets::RecurringTransactionRules::TerminateService
 * (app/services/wallets/recurring_transaction_rules/terminate_service.rb).
 */
class TerminateService extends BaseService
{
    public function __construct(
        private readonly ?RecurringTransactionRule $recurringTransactionRule,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('recurring_transaction_rule');
        $rule = $this->recurringTransactionRule;

        if ($rule === null) {
            return $result->notFoundFailure('recurring_transaction_rule');
        }

        if (! $rule->isTerminated()) {
            $rule->markAsTerminated();
        }

        $result->recurring_transaction_rule = $rule;

        return $result;
    }
}
