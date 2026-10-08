<?php

declare(strict_types=1);

namespace App\Services\Wallets;

use App\Models\Customer;
use App\Support\License;
use App\Services\BaseResult;
use App\Services\Validators\Metadata;
use App\Services\Validators\DecimalAmount;
use App\Services\Validators\ExpirationDate;

use function count;
use function array_key_exists;

/**
 * Port of Rails' Wallets::ValidateService
 * (app/services/wallets/validate_service.rb).
 *
 * Not ported (TODO(port)):
 * - payment_method validation (no PaymentMethods::ValidateService yet) —
 *   args are accepted and skipped.
 * - connections validation (no BillingObjectConnections::ValidateService
 *   port yet) — args are accepted and skipped.
 */
class ValidateService extends BaseValidator
{
    public const MAXIMUM_WALLETS_PER_CUSTOMER = 6;

    public function __construct(
        BaseResult $result,
        protected array $args,
    ) {
        parent::__construct($result);
    }

    public function valid(): bool
    {
        $this->validOrganizationId();
        $this->validCustomer();

        if (! empty($this->args['paid_credits'])) {
            $this->validPaidCreditsAmount();
        }

        if (! empty($this->args['granted_credits'])) {
            $this->validGrantedCreditsAmount();
        }

        if (! empty($this->args['expiration_at'])) {
            $this->validExpirationAt();
        }

        if (! empty($this->args['recurring_transaction_rules'])) {
            $this->validRecurringTransactionRules();
        }

        if (array_key_exists('transaction_metadata', $this->args) && $this->args['transaction_metadata'] !== null) {
            $this->validMetadata();
        }

        if (($this->args['applies_to'] ?? null) !== null) {
            $this->validLimitations();
        }

        $this->validWalletLimit();

        // TODO(port): valid_payment_method? / valid_connections? (see class
        // docblock).

        if ($this->errors()) {
            $this->result->validationFailure($this->messages());

            return false;
        }

        return true;
    }

    /** Rails: `valid_recurring_transaction_rules?`. */
    private function validRecurringTransactionRules(): void
    {
        $rules = (array) $this->args['recurring_transaction_rules'];

        if (count($rules) > 1) {
            $this->addError('recurring_transaction_rules', 'invalid_number_of_recurring_rules');

            return;
        }

        /** @var array<string, mixed> $first */
        $first = (array) ($rules[0] ?? []);

        if (! RecurringTransactionRules\ValidateService::call(params: $first)) {
            $this->addError('recurring_transaction_rules', 'invalid_recurring_rule');
        }
    }

    private function customer(): ?Customer
    {
        return $this->args['customer'] ?? null;
    }

    private function organizationId(): mixed
    {
        return $this->args['organization_id'] ?? null;
    }

    private function validOrganizationId(): bool
    {
        if (($this->organizationId() ?? '') === '') {
            return $this->addError('organization_id', 'blank');
        }

        $customer = $this->customer();

        if ($customer === null || $customer->organization_id === $this->organizationId()) {
            return true;
        }

        return $this->addError('organization_id', 'invalid');
    }

    private function validWalletLimit(): bool
    {
        $customer = $this->customer();

        if ($customer === null) {
            return true;
        }

        $organization = $customer->organization;
        $maxWallets = null;

        if ($organization !== null && $this->eventsTargetingWalletsEnabled($organization)) {
            $maxWallets = $organization->max_wallets;
        }

        $customerAllowedWallets = $maxWallets ?? self::MAXIMUM_WALLETS_PER_CUSTOMER;

        if ($customer->wallets()->active()->count() >= $customerAllowedWallets) {
            return $this->addError('customer', 'wallet_limit_reached');
        }

        return true;
    }

    /** Rails: `organization.events_targeting_wallets_enabled?` (premium integration). */
    private function eventsTargetingWalletsEnabled(object $organization): bool
    {
        return $this->isPremium()
            && in_array('events_targeting_wallets', (array) ($organization->premium_integrations ?? []), true);
    }

    private function isPremium(): bool
    {
        return License::premium();
    }

    private function validCustomer(): bool
    {
        if ($this->customer() === null) {
            return $this->addError('customer', 'customer_not_found');
        }

        return true;
    }

    private function validPaidCreditsAmount(): bool
    {
        if (DecimalAmount::validAmount($this->args['paid_credits'])) {
            return true;
        }

        $this->addError('paid_credits', 'invalid_paid_credits');
        $this->addError('paid_credits', 'invalid_amount');

        return false;
    }

    private function validGrantedCreditsAmount(): bool
    {
        if (DecimalAmount::validAmount($this->args['granted_credits'])) {
            return true;
        }

        $this->addError('granted_credits', 'invalid_granted_credits');
        $this->addError('granted_credits', 'invalid_amount');

        return false;
    }

    private function validExpirationAt(): bool
    {
        if (ExpirationDate::valid($this->args['expiration_at'])) {
            return true;
        }

        return $this->addError('expiration_at', 'invalid_date');
    }

    private function validMetadata(): bool
    {
        $validator = new Metadata($this->args['transaction_metadata']);

        if (! $validator->valid()) {
            foreach ($validator->errors as $field => $errorCode) {
                $this->addError($field, $errorCode);
            }

            return false;
        }

        return true;
    }

    private function validLimitations(): bool
    {
        if ((new ValidateLimitationsService($this->result, $this->args))->valid()) {
            return true;
        }

        return $this->addError('applies_to', 'invalid_limitations');
    }
}
