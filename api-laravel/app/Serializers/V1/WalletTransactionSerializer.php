<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Support\MoneyMath;
use App\Models\WalletTransaction;
use App\Serializers\Base\ModelSerializer;
use App\Serializers\Base\CollectionSerializer;
use App\Serializers\V1\Concerns\FormatsDatetime;

/**
 * Port of Rails' V1::WalletTransactionSerializer
 * (app/serializers/v1/wallet_transaction_serializer.rb).
 */
class WalletTransactionSerializer extends ModelSerializer
{
    use FormatsDatetime;

    /** @return array<string, mixed> */
    public function serialize(): array
    {
        /** @var WalletTransaction $transaction */
        $transaction = $this->model;

        $payload = [
            'lago_id' => $transaction->id,
            'lago_wallet_id' => $transaction->wallet_id,
            'lago_invoice_id' => $transaction->invoice_id,
            'lago_credit_note_id' => $transaction->credit_note_id,
            'lago_voided_invoice_id' => $transaction->voided_invoice_id,
            'billing_entity_code' => $this->billingEntityCode($transaction),
            'status' => $transaction->statusEnum()?->label(),
            'source' => $transaction->sourceEnum()?->label(),
            'transaction_status' => $transaction->transactionStatusEnum()?->label(),
            'transaction_type' => $transaction->transactionTypeEnum()?->label(),
            // Rails' ActiveSupport JSON encoder renders BigDecimal attributes
            // and BigDecimal division results with to_s("F").
            'amount' => MoneyMath::toF((string) $transaction->amount),
            'credit_amount' => MoneyMath::toF((string) $transaction->credit_amount),
            'remaining_amount_cents' => $transaction->remaining_amount_cents,
            'remaining_credit_amount' => $transaction->remainingCreditAmount() === null
                ? null
                : MoneyMath::toF((string) $transaction->remainingCreditAmount()),
            'priority' => $transaction->priority,
            'purchase_order_number' => $transaction->resolvedPurchaseOrderNumber(),
            'settled_at' => $this->serializeDatetime($transaction->settled_at),
            'failed_at' => $this->serializeDatetime($transaction->failed_at),
            'created_at' => $this->serializeDatetime($transaction->created_at),
            'invoice_requires_successful_payment' => (bool) $transaction->invoice_requires_successful_payment,
            'metadata' => $transaction->metadata,
            'name' => $transaction->name,
        ];

        if ($this->include('wallet')) {
            $payload['wallet'] = (new WalletSerializer($transaction->wallet))->serialize();
        }

        if ($this->include('applied_invoice_custom_sections')) {
            $payload = [...$payload, ...(new CollectionSerializer(
                $this->model->appliedInvoiceCustomSections()->get(),
                AppliedInvoiceCustomSectionSerializer::class,
                ['collection_name' => 'applied_invoice_custom_sections'],
            ))->serialize()];
        }

        return [...$payload, ...$this->paymentMethod($transaction)];
    }

    private function billingEntityCode(WalletTransaction $transaction): ?string
    {
        return ($transaction->billingEntity ?? $transaction->wallet->billingEntity)?->code;
    }

    /** @return array<string, mixed> */
    private function paymentMethod(WalletTransaction $transaction): array
    {
        return [
            'payment_method' => [
                'payment_method_id' => $transaction->payment_method_id,
                'payment_method_type' => $transaction->payment_method_type,
            ],
        ];
    }
}
