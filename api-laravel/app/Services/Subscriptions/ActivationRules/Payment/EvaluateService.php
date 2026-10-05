<?php

declare(strict_types=1);

namespace App\Services\Subscriptions\ActivationRules\Payment;

use App\Services\BaseResult;
use App\Services\BaseService;
use InvalidArgumentException;
use App\Models\Subscription\ActivationRule;

/**
 * Port of Rails' Subscriptions::ActivationRules::Payment::EvaluateService
 * (app/services/subscriptions/activation_rules/payment/evaluate_service.rb).
 *
 * An inactive rule moves to pending when it is applicable (its expires_at
 * computed from timeout_hours), else to not_applicable. A pending rule
 * transitions to the explicitly given status (satisfied/failed/expired/...).
 */
class EvaluateService extends BaseService
{
    public function __construct(
        private readonly ActivationRule $rule,
        private readonly ?string $status = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('rule');

        $status = (string) $this->rule->status;

        if ($status === 'inactive') {
            $this->evaluateInactiveRule();
        } elseif ($status === 'pending') {
            $this->transitionPendingRule();
        }

        $result->rule = $this->rule;

        return $result;
    }

    // -- Steps ------------------------------------------------------------------------

    protected function evaluateInactiveRule(): void
    {
        if ($this->rule->applicable()) {
            $this->rule->expires_at = $this->computeExpiresAt();
            $this->rule->transitionTo('pending');
        } else {
            $this->rule->transitionTo('not_applicable');
        }

        $this->rule->save();
    }

    protected function transitionPendingRule(): void
    {
        if ($this->status === null || $this->status === '') {
            throw new InvalidArgumentException('status required to transition a pending rule');
        }

        $this->rule->transitionTo($this->status);
        $this->rule->save();
    }

    protected function computeExpiresAt(): ?\Carbon\CarbonInterface
    {
        if ((int) $this->rule->timeout_hours === 0) {
            return null;
        }

        return now()->addHours((int) $this->rule->timeout_hours);
    }
}
