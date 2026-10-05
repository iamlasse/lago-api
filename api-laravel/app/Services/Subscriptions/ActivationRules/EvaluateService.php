<?php

declare(strict_types=1);

namespace App\Services\Subscriptions\ActivationRules;

use App\Models\Subscription;
use App\Services\BaseResult;
use App\Services\BaseService;

/**
 * Port of Rails' Subscriptions::ActivationRules::EvaluateService
 * (app/services/subscriptions/activation_rules/evaluate_service.rb) —
 * evaluates every activation rule of the subscription.
 */
class EvaluateService extends BaseService
{
    public function __construct(
        private readonly Subscription $subscription,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('subscription', 'rules');

        $result->rules = [];

        $rules = [];

        foreach ($this->subscription->activationRules()->get() as $rule) {
            $rule->evaluate();
            $rules[] = $rule;
        }

        $result->rules = $rules;

        $result->subscription = $this->subscription;

        return $result;
    }
}
