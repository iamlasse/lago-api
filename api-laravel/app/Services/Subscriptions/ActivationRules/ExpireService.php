<?php

declare(strict_types=1);

namespace App\Services\Subscriptions\ActivationRules;

use App\Models\Subscription;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\Failures\ValidationFailure;

/**
 * Port of Rails' Subscriptions::ActivationRules::ExpireService
 * (app/services/subscriptions/activation_rules/expire_service.rb) — expires
 * a still-incomplete subscription whose pending rule timed out.
 */
class ExpireService extends BaseService
{
    public function __construct(
        private readonly Subscription $subscription,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('subscription');

        $cancelResult = CancelService::call(
            subscription: $this->subscription,
            ruleStatus: 'expired',
            cancellationReason: 'timeout',
        );

        if ($cancelResult->failure()) {
            if ($this->subscriptionAlreadyResolved($cancelResult)) {
                $result->subscription = $this->subscription;

                return $result;
            }

            $cancelResult->raiseIfError();
        }

        $result->subscription = $cancelResult->subscription;

        return $result;
    }

    // -- Steps ------------------------------------------------------------------------

    protected function subscriptionAlreadyResolved(BaseResult $cancelResult): bool
    {
        $error = $cancelResult->getError();

        return $error instanceof ValidationFailure
            && (array) ($error->messages ?? []) === ['subscription' => ['subscription_already_resolved']];
    }
}
