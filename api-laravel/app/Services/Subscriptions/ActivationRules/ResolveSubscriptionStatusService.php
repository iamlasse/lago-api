<?php

declare(strict_types=1);

namespace App\Services\Subscriptions\ActivationRules;

use App\Jobs\SendWebhookJob;
use App\Models\Subscription;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\Subscription\ActivationRule;
use App\Services\Subscriptions\ActivateService;

/**
 * Port of Rails' Subscriptions::ActivationRules::ResolveSubscriptionStatusService
 * (app/services/subscriptions/activation_rules/resolve_subscription_status_service.rb)
 * — after a rule resolution, activates the subscription when every rule is
 * fulfilled, cancels it when any rule is rejected.
 *
 * Not ported (dependency does not exist yet):
 * - TODO(port): Utils::ActivityLog.produce_after_commit
 *   ("subscription.canceled").
 */
class ResolveSubscriptionStatusService extends BaseService
{
    public function __construct(
        private readonly Subscription $subscription,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('subscription');

        if (! $this->subscription->incomplete()) {
            $result->subscription = $this->subscription;

            return $result;
        }

        if ($this->allRulesSatisfied()) {
            ActivateService::callBang(subscription: $this->subscription);
        } elseif ($this->anyRuleFailed()) {
            $this->subscription->markAsCanceled();
            $this->subscription->save();

            SendWebhookJob::performLater('subscription.canceled', $this->subscription);

            // TODO(port): Utils::ActivityLog.produce_after_commit
            // (subscription, "subscription.canceled").
        }

        $result->subscription = $this->subscription;

        return $result;
    }

    // -- Steps ------------------------------------------------------------------------

    protected function allRulesSatisfied(): bool
    {
        return ! $this->subscription->activationRules()
            ->whereNotIn('status', ActivationRule::FULFILLED_STATUSES)
            ->exists();
    }

    protected function anyRuleFailed(): bool
    {
        return $this->subscription->activationRules()
            ->rejected()
            ->exists();
    }
}
