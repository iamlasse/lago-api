<?php

declare(strict_types=1);

namespace App\Services\Wallets\RecurringTransactionRules;

use App\Models\Wallet;
use App\Services\BaseResult;
use App\Models\PaymentMethod;
use App\Services\BaseService;
use App\Enums\RecurringTransactionMethod;
use App\Enums\RecurringTransactionTrigger;
use App\Enums\RecurringTransactionInterval;
use App\Services\Validators\WalletTransactionAmountLimits;
use App\Services\InvoiceCustomSections\AttachToResourceService;

use function array_key_exists;

/**
 * Port of Rails' Wallets::RecurringTransactionRules::CreateService
 * (app/services/wallets/recurring_transaction_rules/create_service.rb) —
 * creates the (single) recurring transaction rule of a wallet create
 * payload.
 *
 * Not ported (TODO(port)):
 * - PaymentMethods::ValidateService (payment-methods slice) — the payment
 *   method is resolved for the result payload but its type/id are not
 *   validated.
 * - BillingObjectConnections::ValidateService / AttachToResourceService —
 *   the `connections` param is ignored.
 * - PaperTrail / activity-log middleware.
 */
class CreateService extends BaseService
{
    public function __construct(
        private readonly Wallet $wallet,
        private readonly array $walletParams,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('recurring_transaction_rule', 'payment_method');
        $wallet = $this->wallet;

        // Rails: `return unless License.premium?`
        if (! $this->premium()) {
            return $result;
        }

        // TODO(port): `return result unless valid_payment_method?` —
        // PaymentMethods::ValidateService.
        $result->payment_method = $this->paymentMethod();

        // TODO(port): `return result unless valid_connections?` —
        // BillingObjectConnections::ValidateService.

        // Rails: `rescue BaseService::FailedResult => e; e.result`.
        return $this->rescueFailures(function () use ($result, $wallet): BaseResult {
            return $this->createRule($result, $wallet);
        }, $result);
    }

    /** Rails: `String#presence`. */
    private static function presence(mixed $value): ?string
    {
        return (is_string($value) && mb_trim($value) === '') ? null : $value;
    }

    private function createRule(BaseResult $result, Wallet $wallet): BaseResult
    {
        $ruleParams = $this->ruleParams();
        $paidCredits = null;
        $grantedCredits = null;

        if ($this->method() === 'fixed' && ! array_key_exists('paid_credits', $ruleParams) && ! array_key_exists('granted_credits', $ruleParams)) {
            $paidCredits = $this->walletParams['paid_credits'] ?? null;
            $grantedCredits = $this->walletParams['granted_credits'] ?? null;
        }

        $attributes = [
            'organization_id' => $wallet->organization_id,
            'paid_credits' => $ruleParams['paid_credits'] ?? $paidCredits ?? '0.0',
            'granted_credits' => $ruleParams['granted_credits'] ?? $grantedCredits ?? '0.0',
            'threshold_credits' => $ruleParams['threshold_credits'] ?? '0.0',
            'interval' => RecurringTransactionInterval::fromOption($ruleParams['interval'] ?? null),
            'method' => RecurringTransactionMethod::fromOption($this->method()),
            'started_at' => $ruleParams['started_at'] ?? null,
            'expiration_at' => $ruleParams['expiration_at'] ?? null,
            'target_ongoing_balance' => $ruleParams['target_ongoing_balance'] ?? null,
            'trigger' => RecurringTransactionTrigger::fromOption($ruleParams['trigger'] ?? null),
            'transaction_metadata' => $ruleParams['transaction_metadata'] ?? [],
            'transaction_name' => self::presence($ruleParams['transaction_name'] ?? null),
            'purchase_order_number' => $ruleParams['purchase_order_number'] ?? null,
        ];

        if (array_key_exists('ignore_paid_top_up_limits', $ruleParams)) {
            $attributes['ignore_paid_top_up_limits'] =
                filter_var($ruleParams['ignore_paid_top_up_limits'], FILTER_VALIDATE_BOOLEAN);
        }

        // Rails: ActiveModel::Type::Boolean.new.cast(...) == true — only a
        // truthy input grants; everything else (including nil) is false.
        $attributes['grants_target_top_up'] = $this->method() === 'target'
            ? filter_var($ruleParams['grants_target_top_up'] ?? null, FILTER_VALIDATE_BOOLEAN) === true
            : null;

        if (array_key_exists('payment_method', $ruleParams)) {
            if (array_key_exists('payment_method_type', (array) ($ruleParams['payment_method'] ?? []))) {
                $attributes['payment_method_type'] = $ruleParams['payment_method']['payment_method_type'];
            }

            if (array_key_exists('payment_method_id', (array) ($ruleParams['payment_method'] ?? []))) {
                $attributes['payment_method_id'] = $ruleParams['payment_method']['payment_method_id'];
            }
        }

        $attributes['invoice_requires_successful_payment'] = array_key_exists('invoice_requires_successful_payment', $ruleParams)
            ? filter_var($ruleParams['invoice_requires_successful_payment'], FILTER_VALIDATE_BOOLEAN)
            : (bool) $wallet->invoice_requires_successful_payment;

        $this->validatePaidCredits(
            $result,
            creditsAmount: (string) $attributes['paid_credits'],
            ignoreValidation: (bool) ($attributes['ignore_paid_top_up_limits'] ?? false),
        );

        $rule = $wallet->recurringTransactionRules()->make($attributes);

        // Rails: `wallet.recurring_transaction_rules.create!(attributes)` —
        // a RecordInvalid maps to record_validation_failure.
        $errors = $rule->validateAttributes();

        if ($errors !== []) {
            $result->recordValidationFailure($errors)->raiseIfError();
        }

        $rule->save();

        if (array_key_exists('invoice_custom_section', $ruleParams)) {
            // Rails discards the inner result (plain .call).
            AttachToResourceService::call(resource: $rule, params: $ruleParams);
        }

        // TODO(port): BillingObjectConnections::AttachToResourceService
        // (rule_params[:connections]).

        $result->recurring_transaction_rule = $rule;

        return $result;
    }

    /** Rails: `validate_paid_credits!`. */
    private function validatePaidCredits(BaseResult $result, string $creditsAmount, bool $ignoreValidation): void
    {
        if ($this->method() !== 'fixed') {
            return;
        }

        // BigDecimal(credits_amount).floor(5).zero?
        if (bccomp(bcadd($creditsAmount, '0', 5), '0', 5) === 0) {
            return;
        }

        $validator = new WalletTransactionAmountLimits(
            result: $result,
            wallet: $this->wallet,
            creditsAmount: $creditsAmount,
            ignoreValidation: $ignoreValidation,
        );

        if (! $validator->valid()) {
            $result->singleValidationFailure('invalid_recurring_rule', 'recurring_transaction_rules');
            $result->raiseIfError();
        }
    }

    /** @return array<string, mixed> */
    private function ruleParams(): array
    {
        /** @var array<string, mixed> $first */
        return $this->walletParams['recurring_transaction_rules'][0] ?? [];
    }

    /** Rails: `method` — `rule_params[:method] || "fixed"`. */
    private function method(): string
    {
        $method = $this->ruleParams()['method'] ?? null;

        return is_string($method) && $method !== '' ? $method : 'fixed';
    }

    private function paymentMethod(): ?PaymentMethod
    {
        $ruleParams = $this->ruleParams();
        $paymentMethodId = $ruleParams['payment_method']['payment_method_id'] ?? null;

        if ($paymentMethodId === null || $paymentMethodId === '') {
            return null;
        }

        /** @var PaymentMethod|null */
        return PaymentMethod::query()
            ->where('organization_id', $this->wallet->organization_id)
            ->find($paymentMethodId);
    }
}
