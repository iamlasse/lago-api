<?php

declare(strict_types=1);

namespace App\Services\Subscriptions\ActivationRules;

use App\Models\Subscription;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\Subscription\ActivationRule;

/**
 * Port of Rails' Subscriptions::ActivationRules::ApplyService
 * (app/services/subscriptions/activation_rules/apply_service.rb) — replaces
 * a pending subscription's activation rules with the requested set.
 */
class ApplyService extends BaseService
{
    public function __construct(
        private readonly Subscription $subscription,
        /** @var list<array<string, mixed>>|null */
        private readonly ?array $activationRules,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('activation_rules');

        if ($this->activationRules === null) {
            return $result;
        }

        if (! $this->subscription->pending()) {
            return $result->singleValidationFailure('subscription_not_pending', 'activation_rules');
        }

        $this->subscription->activationRules()->delete();

        foreach ($this->activationRules as $ruleParams) {
            $ruleParams = (array) $ruleParams;

            // Rails slices [:type, :timeout_hours] from the params.
            $attributes = [
                'organization_id' => $this->subscription->organization_id,
                'subscription_id' => $this->subscription->id,
                'status' => ActivationRule::STATUSES['inactive'],
                'type' => $ruleParams['type'] ?? null,
                'timeout_hours' => (int) ($ruleParams['timeout_hours'] ?? 0),
            ];

            $type = $attributes['type'];
            if (is_string($type) && isset(ActivationRule::STI_MAPPING[$type])) {
                /** @var ActivationRule */
                $rule = new (ActivationRule::STI_MAPPING[$type])($attributes);
            } else {
                // Unknown types fail on the PG enum insert, like Rails'
                // inclusion validation + create!.
                $rule = new ActivationRule($attributes);
                $rule->type = $type;
            }

            $rule->save();
        }

        $result->activation_rules = $this->subscription->activationRules()->get();

        return $result;
    }
}
