<?php

declare(strict_types=1);

namespace App\Services\Wallets\RecurringTransactionRules;

use App\Models\Wallet;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Enums\RecurringTransactionMethod;
use App\Enums\RecurringTransactionTrigger;
use App\Enums\RecurringTransactionInterval;
use App\Services\InvoiceCustomSections\AttachToResourceService;

use function array_key_exists;

/**
 * Port of Rails' Wallets::RecurringTransactionRules::UpdateService
 * (app/services/wallets/recurring_transaction_rules/update_service.rb) —
 * syncs the wallet's recurring_transaction_rules payload: updates the
 * matched active rules, creates the ones without a lago_id and terminates
 * every rule that dropped out of the payload.
 *
 * Not ported (TODO(port)):
 * - PaymentMethods::ValidateService (payment-methods slice) — the payment
 *   method is resolved per payload but its type/id are not validated.
 * - BillingObjectConnections::ValidateService / AttachToResourceService —
 *   the `connections` param is stripped and ignored.
 * - PaperTrail / activity-log middleware.
 */
class UpdateService extends BaseService
{
    public function __construct(
        private readonly Wallet $wallet,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('wallet', 'payment_method');
        $wallet = $this->wallet;

        // TODO(port): `return result unless valid_payment_methods?` —
        // PaymentMethods::ValidateService.
        // TODO(port): `return result unless valid_connections?` —
        // BillingObjectConnections::ValidateService.

        // Rails: `rescue BaseService::FailedResult => e; e.result`.
        return $this->rescueFailures(function () use ($result, $wallet): BaseResult {
            $createdRecurringRulesIds = [];

            foreach ($this->hashRecurringRules() as $payloadRule) {
                $lagoId = $payloadRule['lago_id'] ?? null;
                $ruleAttributes = $payloadRule;

                // Normalize transaction_name to nil if empty
                if (array_key_exists('transaction_name', $ruleAttributes)) {
                    $ruleAttributes['transaction_name'] = self::presence($ruleAttributes['transaction_name']);
                }

                foreach (['paid_credits', 'granted_credits', 'threshold_credits'] as $creditAttr) {
                    if (array_key_exists($creditAttr, $ruleAttributes) && $ruleAttributes[$creditAttr] === null) {
                        $ruleAttributes[$creditAttr] = '0.0';
                    }
                }

                if (array_key_exists('payment_method', $ruleAttributes)) {
                    if (array_key_exists('payment_method_type', (array) ($ruleAttributes['payment_method'] ?? []))) {
                        $ruleAttributes['payment_method_type'] = $ruleAttributes['payment_method']['payment_method_type'];
                    }

                    if (array_key_exists('payment_method_id', (array) ($ruleAttributes['payment_method'] ?? []))) {
                        $ruleAttributes['payment_method_id'] = $ruleAttributes['payment_method']['payment_method_id'];
                    }

                    unset($ruleAttributes['payment_method']);
                }

                $connections = $ruleAttributes['connections'] ?? null;
                unset($ruleAttributes['connections']);

                /** @var \App\Models\RecurringTransactionRule|null $recurringRule */
                $recurringRule = $wallet->recurringTransactionRules()
                    ->active()
                    ->find($lagoId);

                $this->normalizeGrantsTargetTopUp($ruleAttributes, $recurringRule);

                $invoiceCustomSection = null;
                if (array_key_exists('invoice_custom_section', $ruleAttributes)) {
                    $invoiceCustomSection = ['invoice_custom_section' => $ruleAttributes['invoice_custom_section']];
                    unset($ruleAttributes['invoice_custom_section']);
                }

                if ($recurringRule !== null) {
                    if ($invoiceCustomSection !== null) {
                        // Rails discards the inner result (plain .call).
                        AttachToResourceService::call(resource: $recurringRule, params: $invoiceCustomSection);
                    }

                    $recurringRule->fill($this->castEnums($ruleAttributes));

                    $errors = $recurringRule->validateAttributes();

                    if ($errors !== []) {
                        $result->recordValidationFailure($errors)->raiseIfError();
                    }

                    $recurringRule->save();

                    // TODO(port): attach_connections —
                    // BillingObjectConnections::AttachToResourceService.
                } else {
                    if (! array_key_exists('invoice_requires_successful_payment', $ruleAttributes)) {
                        $ruleAttributes['invoice_requires_successful_payment'] = (bool) $wallet->invoice_requires_successful_payment;
                    }

                    $createdRecurringRule = $wallet->recurringTransactionRules()->make(
                        $this->castEnums($ruleAttributes + ['organization_id' => $wallet->organization_id]),
                    );

                    $errors = $createdRecurringRule->validateAttributes();

                    if ($errors !== []) {
                        $result->recordValidationFailure($errors)->raiseIfError();
                    }

                    $createdRecurringRule->save();

                    if ($invoiceCustomSection !== null) {
                        AttachToResourceService::call(resource: $createdRecurringRule, params: $invoiceCustomSection);
                    }

                    // TODO(port): attach_connections —
                    // BillingObjectConnections::AttachToResourceService.

                    $createdRecurringRulesIds[] = $createdRecurringRule->id;
                }
            }

            // NOTE: Delete recurring_rules that are no more linked to the wallet
            $this->sanitizeRecurringRules($this->hashRecurringRules(), $createdRecurringRulesIds);

            $result->wallet = $wallet;

            return $result;
        }, $result);
    }

    /** Rails: `String#presence`. */
    private static function presence(mixed $value): ?string
    {
        return (is_string($value) && mb_trim($value) === '') ? null : $value;
    }

    /**
     * Rails: `sanitize_recurring_rules` — terminate every wallet rule that
     * is neither referenced by the payload nor just created.
     *
     * @param  list<array<string, mixed>>  $argsRecurringRules
     * @param  list<string>  $createdRecurringRulesIds
     */
    private function sanitizeRecurringRules(array $argsRecurringRules, array $createdRecurringRulesIds): void
    {
        $updatedRecurringRulesIds = array_values(array_filter(
            array_map(fn (array $m) => $m['lago_id'] ?? null, $argsRecurringRules),
        ));

        $notNeededIds = $this->wallet->recurringTransactionRules()
            ->pluck('id')
            ->diff($updatedRecurringRulesIds)
            ->diff($createdRecurringRulesIds)
            ->values()
            ->all();

        if ($notNeededIds === []) {
            return;
        }

        $this->wallet->recurringTransactionRules()
            ->whereIn('id', $notNeededIds)
            ->get()
            ->each(fn ($rule) => TerminateService::call(recurringTransactionRule: $rule));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function hashRecurringRules(): array
    {
        return array_map(fn ($m) => (array) $m, $this->params);
    }

    /**
     * Rails: `normalize_grants_target_top_up!` — target rules carry a
     * boolean (defaulting to false for legacy nil rows); every other method
     * carries nil.
     *
     * @param  array<string, mixed>  $ruleAttributes
     */
    private function normalizeGrantsTargetTopUp(array &$ruleAttributes, ?\App\Models\RecurringTransactionRule $recurringRule): void
    {
        $effectiveMethod = $ruleAttributes['method'] ?? $recurringRule?->methodEnum()?->label();

        if ($effectiveMethod === 'target') {
            if (array_key_exists('grants_target_top_up', $ruleAttributes)) {
                $ruleAttributes['grants_target_top_up'] =
                    filter_var($ruleAttributes['grants_target_top_up'], FILTER_VALIDATE_BOOLEAN);
            } elseif ($recurringRule?->grants_target_top_up === null) {
                $ruleAttributes['grants_target_top_up'] = false;
            }
        } else {
            $ruleAttributes['grants_target_top_up'] = null;
        }
    }

    /**
     * Rails assigns enum NAMES (`rule_attributes[:interval] = "weekly"`) and
     * ActiveRecord casts to the stored integer — map the wire strings here.
     *
     * @param  array<string, mixed>  $ruleAttributes
     * @return array<string, mixed>
     */
    private function castEnums(array $ruleAttributes): array
    {
        if (array_key_exists('interval', $ruleAttributes)) {
            $ruleAttributes['interval'] = RecurringTransactionInterval::fromOption($ruleAttributes['interval']);
        }

        if (array_key_exists('method', $ruleAttributes)) {
            $ruleAttributes['method'] = RecurringTransactionMethod::fromOption($ruleAttributes['method']);
        }

        if (array_key_exists('trigger', $ruleAttributes)) {
            $ruleAttributes['trigger'] = RecurringTransactionTrigger::fromOption($ruleAttributes['trigger']);
        }

        return $ruleAttributes;
    }
}
