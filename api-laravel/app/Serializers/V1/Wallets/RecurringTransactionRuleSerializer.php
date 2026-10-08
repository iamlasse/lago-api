<?php

declare(strict_types=1);

namespace App\Serializers\V1\Wallets;

use App\Support\MoneyMath;
use App\Models\RecurringTransactionRule;
use App\Serializers\Base\ModelSerializer;
use App\Serializers\Base\CollectionSerializer;
use App\Serializers\V1\Concerns\FormatsDatetime;
use App\Serializers\V1\AppliedInvoiceCustomSectionSerializer;

/**
 * Port of Rails' V1::Wallets::RecurringTransactionRuleSerializer
 * (app/serializers/v1/wallets/recurring_transaction_rule_serializer.rb).
 *
 * @phpstan-extends ModelSerializer<RecurringTransactionRule>
 */
class RecurringTransactionRuleSerializer extends ModelSerializer
{
    use FormatsDatetime;

    /** @return array<string, mixed> */
    public function serialize(): array
    {
        /** @var RecurringTransactionRule $rule */
        $rule = $this->model;

        $payload = [
            'lago_id' => $rule->id,
            'paid_credits' => MoneyMath::toF((string) $rule->paid_credits),
            'granted_credits' => MoneyMath::toF((string) $rule->granted_credits),
            'grants_target_top_up' => (bool) $rule->grants_target_top_up,
            'interval' => $rule->intervalEnum()?->label(),
            'method' => $rule->methodEnum()?->label(),
            'started_at' => $this->serializeDatetime($rule->started_at),
            'expiration_at' => $this->serializeDatetime($rule->expiration_at),
            'status' => $rule->statusEnum()?->label(),
            'target_ongoing_balance' => $rule->target_ongoing_balance === null
                ? null
                : MoneyMath::toF((string) $rule->target_ongoing_balance),
            'threshold_credits' => $rule->threshold_credits === null
                ? null
                : MoneyMath::toF((string) $rule->threshold_credits),
            'trigger' => $rule->triggerEnum()?->label(),
            'created_at' => $this->serializeDatetime($rule->created_at),
            'invoice_requires_successful_payment' => (bool) $rule->invoice_requires_successful_payment,
            'transaction_metadata' => $rule->transaction_metadata,
            'transaction_name' => $rule->transaction_name,
            'purchase_order_number' => $rule->purchase_order_number,
            'ignore_paid_top_up_limits' => (bool) $rule->ignore_paid_top_up_limits,
        ];

        $payload = [...$payload, ...(new CollectionSerializer(
            $rule->appliedInvoiceCustomSections()->get(),
            AppliedInvoiceCustomSectionSerializer::class,
            ['collection_name' => 'applied_invoice_custom_sections'],
        ))->serialize()];

        $payload['payment_method'] = [
            'payment_method_id' => $rule->payment_method_id,
            'payment_method_type' => $rule->payment_method_type,
        ];

        // Keyed by category, mirroring the shape accepted on create/update.
        $payload['connections'] = collect($rule->connectionRouting())
            ->mapWithKeys(fn (array $routing, string $category) => [$category => [
                'behavior' => $routing['behavior'],
                'code' => $routing['code'],
            ]])
            ->all();

        return $payload;
    }
}
