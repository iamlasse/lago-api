<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\WalletTransaction as WalletTransactionModel;

/**
 * Field resolvers for the frozen SDL's `WalletTransaction` type — the port
 * of Rails' Types::WalletTransactions::Object. Attribute-shaped fields
 * resolve through the snake_case fallback; only the computed fields below
 * need methods.
 */
class WalletTransaction
{
    public static function bigDecimalString(mixed $value): ?string
    {
        if ($value === null || ! is_numeric((string) $value)) {
            return $value === null ? null : (string) $value;
        }

        $s = (string) $value;

        if (! str_contains($s, '.')) {
            return $s;
        }

        $trimmed = mb_rtrim($s, '0');

        return str_ends_with($trimmed, '.') ? $trimmed.'0' : $trimmed;
    }

    /** Rails: the status enum name — the column stores the integer position. */
    public function status(WalletTransactionModel $transaction): ?string
    {
        return $transaction->statusEnum()?->label();
    }

    /** Rails: the transaction_status enum name. */
    public function transactionStatus(WalletTransactionModel $transaction): ?string
    {
        return $transaction->transactionStatusEnum()?->label();
    }

    /** Rails: the transaction_type enum name. */
    public function transactionType(WalletTransactionModel $transaction): ?string
    {
        return $transaction->transactionTypeEnum()?->label();
    }

    /** Rails: the source enum name. */
    public function source(WalletTransactionModel $transaction): ?string
    {
        return $transaction->sourceEnum()?->label();
    }

    /** Rails: the amount/credit_amount columns render as BigDecimal#to_s ('15.00000' → '15.0'). */
    public function amount(WalletTransactionModel $transaction): ?string
    {
        return self::bigDecimalString($transaction->amount);
    }

    public function creditAmount(WalletTransactionModel $transaction): ?string
    {
        return self::bigDecimalString($transaction->credit_amount);
    }

    /** Rails: `object.invoice&.visible? ? object.invoice : nil`. */
    public function invoice(WalletTransactionModel $transaction): mixed
    {
        $invoice = $transaction->invoice;

        return $invoice !== null && $invoice->isVisible() ? $invoice : null;
    }

    /** Rails: `object.wallet.name` (null when the wallet is gone). */
    public function walletName(WalletTransactionModel $transaction): ?string
    {
        return $transaction->wallet?->name;
    }

    /** Rails: the field is declared `method: :resolved_purchase_order_number`. */
    public function purchaseOrderNumber(WalletTransactionModel $transaction): ?string
    {
        return $transaction->resolvedPurchaseOrderNumber();
    }
}
