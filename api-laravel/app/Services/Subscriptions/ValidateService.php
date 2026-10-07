<?php

declare(strict_types=1);

namespace App\Services\Subscriptions;

use App\Models\Subscription;
use App\Services\BaseResult;
use App\Support\Utils\Datetime;

/**
 * Port of Rails' Subscriptions::ValidateService
 * (app/services/subscriptions/validate_service.rb — a BaseValidator).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): PaymentMethods::ValidateService — payment methods are a later
 *   milestone; a provided payment_method is accepted as-is.
 * - TODO(port): BillingObjectConnections::ValidateService — connections arg
 *   validated there; accepted as-is meanwhile.
 * - TODO(port): Subscriptions::ActivationRules::ValidateService.
 */
class ValidateService
{
    /** @var array<string, list<string>> */
    protected array $errors = [];

    /**
     * @param  array<string, mixed>  $args
     */
    public function __construct(protected BaseResult $result, protected array $args)
    {
    }

    public function valid(): bool
    {
        if (! $this->validCustomer()) {
            return false;
        }

        if (! $this->validPlan()) {
            return false;
        }

        $this->validSubscriptionAt();
        $this->validEndingAt();
        $this->validOnTerminationCreditNote();
        $this->validOnTerminationInvoice();
        $this->validPaymentMethod();
        $this->validConnections();
        $this->validActivationRules();
        $this->validConsolidateInvoice();

        if ($this->errors !== []) {
            $this->result->validationFailure($this->errors);

            return false;
        }

        return true;
    }

    protected function validCustomer(): bool
    {
        if ($this->args['customer'] ?? null) {
            return true;
        }

        $this->result->notFoundFailure('customer');

        return false;
    }

    protected function validPlan(): bool
    {
        if ($this->args['plan'] ?? null) {
            return true;
        }

        $this->result->notFoundFailure('plan');

        return false;
    }

    protected function validSubscriptionAt(): bool
    {
        if (Datetime::validFormat($this->args['subscription_at'] ?? null)) {
            return true;
        }

        return $this->addError('subscription_at', 'invalid_date');
    }

    protected function validEndingAt(): bool
    {
        $endingAt = $this->args['ending_at'] ?? null;

        if ($endingAt === null || $endingAt === '') {
            return true;
        }

        $endingAtParsed = $this->endingAt();
        $subscriptionAtParsed = $this->subscriptionAt();

        if (
            Datetime::validFormat($endingAt)
            && Datetime::validFormat($this->args['subscription_at'] ?? null)
            && $endingAtParsed !== null
            && $subscriptionAtParsed !== null
            && $endingAtParsed->startOfDay()->gt(today())
            && $endingAtParsed->startOfDay()->gt($subscriptionAtParsed->startOfDay())
        ) {
            return true;
        }

        return $this->addError('ending_at', 'invalid_date');
    }

    protected function validOnTerminationCreditNote(): bool
    {
        $value = $this->args['on_termination_credit_note'] ?? null;

        if ($value === null || $value === '') {
            return true;
        }

        if (in_array($value, Subscription::ON_TERMINATION_CREDIT_NOTES, true)) {
            return true;
        }

        return $this->addError('on_termination_credit_note', 'invalid_value');
    }

    protected function validOnTerminationInvoice(): bool
    {
        $value = $this->args['on_termination_invoice'] ?? null;

        if ($value === null || $value === '') {
            return true;
        }

        if (in_array($value, Subscription::ON_TERMINATION_INVOICES, true)) {
            return true;
        }

        return $this->addError('on_termination_invoice', 'invalid_value');
    }

    protected function endingAt(): ?\Carbon\CarbonImmutable
    {
        return Datetime::parseIso8601($this->args['ending_at'] ?? null);
    }

    protected function subscriptionAt(): ?\Carbon\CarbonImmutable
    {
        return Datetime::parseIso8601($this->args['subscription_at'] ?? null);
    }

    protected function validPaymentMethod(): bool
    {
        if (blank($this->args['payment_method'] ?? null)) {
            return true;
        }

        // TODO(port): PaymentMethods::ValidateService — accepted as valid
        // until payment methods are ported.
        return true;
    }

    protected function validConnections(): bool
    {
        if (blank($this->args['connections'] ?? null)) {
            return true;
        }

        // TODO(port): BillingObjectConnections::ValidateService — accepted as
        // valid until billing object connections are ported.
        return true;
    }

    protected function validActivationRules(): bool
    {
        if (blank($this->args['activation_rules'] ?? null)) {
            return true;
        }

        // Port of Subscriptions::ActivationRules::ValidateService — the
        // nested validator shares this result and its errors.
        return (new ActivationRules\ValidateService($this->result, [
            'activation_rules' => $this->args['activation_rules'],
            'payment_method' => $this->args['payment_method'] ?? null,
            'subscription' => $this->args['subscription'] ?? null,
            'customer' => $this->args['customer'] ?? null,
            'subscription_type' => $this->args['subscription_type'] ?? null,
        ]))->valid();
    }

    protected function validConsolidateInvoice(): bool
    {
        if (! ($this->args['consolidate_invoice_provided'] ?? false)) {
            return true;
        }

        $value = $this->args['consolidate_invoice'] ?? null;

        if (in_array($value, [true, false, 'true', 'false'], true)) {
            return true;
        }

        return $this->addError('consolidate_invoice', 'invalid_value');
    }

    protected function addError(string $field, string $errorCode): bool
    {
        $this->errors[$field] ??= [];
        $this->errors[$field][] = $errorCode;

        return false;
    }
}
