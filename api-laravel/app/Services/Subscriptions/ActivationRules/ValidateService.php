<?php

declare(strict_types=1);

namespace App\Services\Subscriptions\ActivationRules;

use App\Models\Subscription;
use App\Services\BaseResult;
use App\Models\Subscription\ActivationRule;
use App\Services\Subscriptions\ActivationRules\Payment\ValidateService as PaymentValidateService;

/**
 * Port of Rails' Subscriptions::ActivationRules::ValidateService
 * (app/services/subscriptions/activation_rules/validate_service.rb — a
 * BaseValidator): the shape check on the activation_rules param, the
 * pending-subscription gate on updates, and the per-type validation.
 */
class ValidateService
{
    protected BaseResult $result;

    /** @var array<string, mixed> */
    protected array $args;

    /** @var array<string, list<string>> */
    protected array $errors = [];

    /**
     * @param  array<string, mixed>  $args  activation_rules:, payment_method:, subscription:,
     *                                      customer:, subscription_type:
     */
    public function __construct(BaseResult $result, array $args)
    {
        $this->result = $result;
        $this->args = $args;
    }

    public function valid(): bool
    {
        $this->validActivationRulesFormat();
        $this->validSubscriptionStatus();

        if (! in_array('invalid_format', (array) ($this->errors['activation_rules'] ?? []), true)) {
            $this->validRules();
        }

        if ($this->errors !== []) {
            $this->result->validationFailure($this->errors);

            return false;
        }

        return true;
    }

    // -- Steps ------------------------------------------------------------------------

    protected function validActivationRulesFormat(): bool
    {
        if (is_array($this->args['activation_rules'] ?? null)) {
            return true;
        }

        $this->addError('activation_rules', 'invalid_format');

        return false;
    }

    protected function validSubscriptionStatus(): bool
    {
        if (($this->args['subscription_type'] ?? null) !== 'update') {
            return true;
        }

        $subscription = $this->args['subscription'] ?? null;

        if ($subscription instanceof Subscription && $subscription->pending()) {
            return true;
        }

        $this->addError('activation_rules', 'subscription_not_pending');

        return false;
    }

    protected function validRules(): bool
    {
        $activationRules = (array) ($this->args['activation_rules'] ?? []);

        if ($activationRules === []) {
            return true;
        }

        foreach ($activationRules as $rule) {
            $rule = (array) $rule;
            $type = $rule['type'] ?? null;

            if (! $this->validRuleType(is_string($type) ? $type : null)) {
                continue;
            }

            $this->validateSpecificRule($rule);
        }

        $this->duplicatedRuleTypes($activationRules);

        return true;
    }

    /** @param list<array<string, mixed>> $activationRules */
    protected function duplicatedRuleTypes(array $activationRules): bool
    {
        $types = array_map(
            fn (array $rule): string => (string) ($rule['type'] ?? ''),
            $activationRules,
        );

        if (count(array_unique($types)) === count($types)) {
            return true;
        }

        $this->addError('activation_rules', 'duplicated_type');

        return false;
    }

    protected function validRuleType(?string $type): bool
    {
        if ($type !== null && array_key_exists($type, ActivationRule::STI_MAPPING)) {
            return true;
        }

        $this->addError('activation_rules', 'invalid_type');

        return false;
    }

    /** @param array<string, mixed> $rule */
    protected function validateSpecificRule(array $rule): void
    {
        $validator = match ((string) ($rule['type'] ?? '')) {
            'payment' => new PaymentValidateService($this->result, [
                'rule' => $rule,
                'payment_method' => $this->args['payment_method'] ?? null,
                'subscription' => $this->args['subscription'] ?? null,
                'customer' => $this->args['customer'] ?? null,
            ]),
            default => null,
        };

        if ($validator === null) {
            return;
        }

        if ($validator->valid()) {
            return;
        }

        foreach ($validator->errors() as $field => $codes) {
            foreach ((array) $codes as $code) {
                $this->addError((string) $field, (string) $code);
            }
        }
    }

    // -- Error plumbing ---------------------------------------------------------------

    protected function addError(string $field, string $errorCode): void
    {
        $this->errors[$field][] = $errorCode;
    }
}
