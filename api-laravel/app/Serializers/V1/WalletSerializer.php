<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Models\Wallet;
use App\Serializers\Base\ModelSerializer;
use App\Serializers\V1\Concerns\FormatsDatetime;

/**
 * Port of Rails' V1::WalletSerializer
 * (app/serializers/v1/wallet_serializer.rb).
 */
class WalletSerializer extends ModelSerializer
{
    use FormatsDatetime;

    /** @return array<string, mixed> */
    public function serialize(): array
    {
        /** @var Wallet $wallet */
        $wallet = $this->model;

        $payload = [
            'lago_id' => $wallet->id,
            'lago_customer_id' => $wallet->customer_id,
            'external_customer_id' => $wallet->customer->external_id,
            'billing_entity_code' => $wallet->resolvedBillingEntity()?->code,
            'status' => $wallet->statusEnum()?->label(),
            'currency' => $wallet->currency,
            'name' => $wallet->name,
            'code' => $wallet->code,
            'purchase_order_number' => $wallet->purchase_order_number,
            'rate_amount' => $wallet->rate_amount,
            'credits_balance' => $wallet->credits_balance,
            'credits_ongoing_balance' => $wallet->credits_ongoing_balance,
            'credits_ongoing_usage_balance' => $wallet->credits_ongoing_usage_balance,
            'balance_cents' => $wallet->balance_cents,
            'ongoing_balance_cents' => $wallet->ongoing_balance_cents,
            'ongoing_usage_balance_cents' => $wallet->ongoing_usage_balance_cents,
            'consumed_credits' => $wallet->consumed_credits,
            'created_at' => $this->serializeDatetime($wallet->created_at),
            'expiration_at' => $this->serializeDatetime($wallet->expiration_at),
            'last_balance_sync_at' => $this->serializeDatetime($wallet->last_balance_sync_at),
            'last_consumed_credit_at' => $this->serializeDatetime($wallet->last_consumed_credit_at),
            // Rails emits the raw Time object here (rendered iso8601 by the
            // JSON encoder); kept identical to the other datetime fields.
            'terminated_at' => $this->serializeDatetime($wallet->terminated_at),
            'invoice_requires_successful_payment' => (bool) $wallet->invoice_requires_successful_payment,
            'paid_top_up_min_amount_cents' => $wallet->paid_top_up_min_amount_cents,
            'paid_top_up_max_amount_cents' => $wallet->paid_top_up_max_amount_cents,
            'priority' => $wallet->priority,
        ];

        if ($this->include('recurring_transaction_rules')) {
            // TODO(port): RecurringTransactionRule has no model yet — Rails
            // serializes the active rules.
            $payload['recurring_transaction_rules'] = [];
        }

        if ($this->include('limitations')) {
            // Rails: payload.merge!(limitations) where the limitations method
            // returns { applies_to: { fee_types:, billable_metric_codes: } }.
            $payload['limitations'] = [
                'applies_to' => [
                    'fee_types' => (array) ($wallet->allowed_fee_types ?? []),
                    'billable_metric_codes' => $wallet->billableMetrics()->pluck('code')->all(),
                ],
            ];
        }

        if ($this->include('applied_invoice_custom_sections')) {
            // TODO(port): Wallet::AppliedInvoiceCustomSection — empty
            // collection.
            $payload['applied_invoice_custom_sections'] = [];
        }

        $payload = [...$payload, ...$this->paymentMethod($wallet)];

        // Keyed by category, mirroring the shape accepted on create/update.
        // Every category is present, with the behaviour ("inherit" when the
        // wallet makes no choice of its own) and the code of the connection
        // actually in effect.
        $payload['connections'] = $wallet->connectionRouting();

        if ($wallet->metadata()->exists()) {
            // TODO(port): V1::MetadataSerializer (Metadata::ItemMetadata
            // slice) — the raw value hash is emitted for now.
            $payload['metadata'] = ['metadata' => $wallet->metadata()->first()?->value];
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function paymentMethod(Wallet $wallet): array
    {
        return [
            'payment_method' => [
                'payment_method_id' => $wallet->payment_method_id,
                'payment_method_type' => $wallet->payment_method_type,
            ],
        ];
    }
}
