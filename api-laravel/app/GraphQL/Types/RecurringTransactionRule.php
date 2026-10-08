<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\RecurringTransactionRule as RecurringTransactionRuleModel;

/**
 * Field resolvers for the frozen SDL's `RecurringTransactionRule` type —
 * the port of Rails' Types::Wallets::RecurringTransactionRules::Object.
 * Attribute-shaped fields resolve through the snake_case fallback; only the
 * computed fields below need methods.
 */
class RecurringTransactionRule
{
    /** Rails: the field is declared `method: :id`. */
    public function lagoId(RecurringTransactionRuleModel $rule): string
    {
        return (string) $rule->id;
    }

    /**
     * Rails: the field is declared `method: :connection_routing` and Rails'
     * ConnectionResolvable#connection_routing returns a LIST of Routing
     * objects — the port's model helper returns the REST-shaped keyed hash,
     * reshaped here into the SDL's list.
     */
    public function connections(RecurringTransactionRuleModel $rule): array
    {
        return array_values(array_map(
            fn (string $category, array $routing) => (object) [
                'category' => $category,
                'behavior' => $routing['behavior'],
                'code' => $routing['code'],
            ],
            array_keys($rule->connectionRouting()),
            array_values($rule->connectionRouting()),
        ));
    }

    /** Rails: the interval enum name — the column stores the integer position. */
    public function interval(RecurringTransactionRuleModel $rule): ?string
    {
        return $rule->intervalEnum()?->label();
    }

    /** Rails: the method enum name (field declared `resolver_method: :method`). */
    public function method(RecurringTransactionRuleModel $rule): ?string
    {
        return $rule->methodEnum()?->label();
    }

    /** Rails: the trigger enum name. */
    public function trigger(RecurringTransactionRuleModel $rule): ?string
    {
        return $rule->triggerEnum()?->label();
    }

    /** Rails: BigDecimal#to_s rendering ('15.00000' → '15.0'). */
    public function paidCredits(RecurringTransactionRuleModel $rule): string
    {
        return WalletTransaction::bigDecimalString((string) $rule->paid_credits) ?? '0.0';
    }

    public function grantedCredits(RecurringTransactionRuleModel $rule): string
    {
        return WalletTransaction::bigDecimalString((string) $rule->granted_credits) ?? '0.0';
    }

    public function thresholdCredits(RecurringTransactionRuleModel $rule): ?string
    {
        if ($rule->threshold_credits === null) {
            return null;
        }

        return WalletTransaction::bigDecimalString((string) $rule->threshold_credits);
    }

    public function targetOngoingBalance(RecurringTransactionRuleModel $rule): ?string
    {
        if ($rule->target_ongoing_balance === null) {
            return null;
        }

        return WalletTransaction::bigDecimalString((string) $rule->target_ongoing_balance);
    }

    /**
     * Rails: the jsonb pairs list — the SDL wants [{key, value}] objects.
     *
     * @return list<object>|null
     */
    public function transactionMetadata(RecurringTransactionRuleModel $rule): ?array
    {
        $metadata = $rule->transaction_metadata;

        if ($metadata === null || ! is_array($metadata)) {
            return null;
        }

        $out = [];

        foreach ($metadata as $item) {
            if (! is_array($item)) {
                continue;
            }

            $out[] = (object) [
                'key' => (string) ($item['key'] ?? ''),
                'value' => (string) ($item['value'] ?? ''),
            ];
        }

        return $out;
    }

    /** Rails: `selected_invoice_custom_sections` through the join table. */
    public function selectedInvoiceCustomSections(RecurringTransactionRuleModel $rule): ?array
    {
        return $rule->selectedInvoiceCustomSections->all();
    }
}
