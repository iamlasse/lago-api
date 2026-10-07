<?php

declare(strict_types=1);

namespace App\Services\Subscriptions\ActivationRules\Payment;

use App\Models\Customer;
use App\Models\Subscription;
use App\Services\BaseResult;

/**
 * Port of Rails' Subscriptions::ActivationRules::Payment::ValidateService
 * (app/services/subscriptions/activation_rules/payment/validate_service.rb —
 * a BaseValidator): a payment activation rule requires a resolvable provider
 * payment method on the subscription or the customer.
 *
 * Not ported (dependency does not exist yet):
 * - TODO(port): PaymentMethod default resolution (PaymentMethods::SetAsDefault
 *   slice owns `customer.default_payment_method`); the lookup below follows
 *   the Rails shape against the ported PaymentMethod model.
 */
class ValidateService
{
    /** @var array<string, list<string>> */
    protected array $errors = [];

    /**
     * @param  array<string, mixed>  $args  rule:, payment_method:, subscription:, customer:
     */
    public function __construct(protected BaseResult $result, protected array $args)
    {
    }

    public function valid(): bool
    {
        $this->validTimeoutHours();
        $this->validPaymentMethod();

        if ($this->errors !== []) {
            $this->result->validationFailure($this->errors);

            return false;
        }

        return true;
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    protected function addError(string $field, string $errorCode): void
    {
        $this->errors[$field][] = $errorCode;
    }

    protected function validTimeoutHours(): bool
    {
        $rule = (array) ($this->args['rule'] ?? []);

        if (! array_key_exists('timeout_hours', $rule)) {
            return true;
        }

        $timeoutHours = $rule['timeout_hours'];

        if (is_int($timeoutHours) && $timeoutHours >= 0) {
            return true;
        }

        $this->addError('timeout_hours', 'value_must_be_positive_or_zero');

        return false;
    }

    protected function validPaymentMethod(): bool
    {
        $effectiveType = $this->effectivePaymentMethodType();

        if ($effectiveType !== null && $effectiveType !== '') {
            if ($this->effectivePaymentMethodTypeIsProvider($effectiveType)
                && $this->resolvedPaymentMethod() !== null) {
                return true;
            }
        } elseif ($this->customerPaymentProvider() && $this->resolvedPaymentMethod() !== null) {
            return true;
        }

        $this->addError('customer', $this->failureErrorCode($effectiveType));

        return false;
    }

    // -- Effective values -------------------------------------------------------------

    /** Rails: PaymentMethod::PAYMENT_METHOD_TYPES[:provider] — 'provider'. */
    protected function effectivePaymentMethodTypeIsProvider(string $type): bool
    {
        return $type === 'provider';
    }

    protected function effectivePaymentMethodType(): ?string
    {
        $paymentMethod = (array) ($this->args['payment_method'] ?? []);

        if (array_key_exists('payment_method_type', $paymentMethod)) {
            $value = $paymentMethod['payment_method_type'];

            return $value === null ? null : (string) $value;
        }

        $subscription = $this->args['subscription'] ?? null;

        $value = $subscription instanceof Subscription ? $subscription->payment_method_type : null;

        return ($value === null || $value === '') ? null : (string) $value;
    }

    protected function effectivePaymentMethodId(): ?string
    {
        $paymentMethod = (array) ($this->args['payment_method'] ?? []);

        if (array_key_exists('payment_method_id', $paymentMethod)) {
            $value = $paymentMethod['payment_method_id'];

            return ($value === null || $value === '') ? null : (string) $value;
        }

        $subscription = $this->args['subscription'] ?? null;

        $value = $subscription instanceof Subscription ? $subscription->payment_method_id : null;

        return ($value === null || $value === '') ? null : (string) $value;
    }

    protected function resolvedPaymentMethod()
    {
        $customer = $this->args['customer'] ?? null;

        if (! $customer instanceof Customer) {
            return null;
        }

        $paymentMethodId = $this->effectivePaymentMethodId();

        if ($paymentMethodId !== null) {
            return $customer->paymentMethods()->whereKey($paymentMethodId)->first();
        }

        // Rails: customer.default_payment_method.
        return $customer->paymentMethods()->where('is_default', true)->first();
    }

    protected function customerPaymentProvider(): bool
    {
        $customer = $this->args['customer'] ?? null;

        return $customer instanceof Customer
            && ($customer->payment_provider ?? null) !== null
            && (string) $customer->payment_provider !== '';
    }

    protected function failureErrorCode(?string $effectiveType): string
    {
        if ($effectiveType !== null && $effectiveType !== '') {
            if (! $this->effectivePaymentMethodTypeIsProvider($effectiveType)) {
                return 'manual_payment_method_invalid_for_payment_activation_rules';
            }
        } elseif (! $this->customerPaymentProvider()) {
            return 'no_linked_payment_provider';
        }

        if ($this->effectivePaymentMethodId() !== null) {
            return 'payment_method_not_found';
        }

        return 'no_default_payment_method';
    }
}
