<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Payment as PaymentModel;

/**
 * Field resolvers for the frozen SDL's `Payment` type (port of Rails'
 * Types::Payments::Object computed fields). Plain columns resolve through
 * the snake_case attribute fallback.
 */
class Payment
{
    /** Rails: payable_payment_status — the payable's payment status wire value. */
    public function payablePaymentStatus(PaymentModel $root): ?string
    {
        return $root->payablePaymentStatus();
    }

    /** Rails: payment_provider_type — the provider's payment_type slug. */
    public function paymentProviderType(PaymentModel $root): ?string
    {
        return $root->paymentProvider?->paymentType();
    }

    /** Rails: payment_type — the pg-enum column (label string or position). */
    public function paymentType(PaymentModel $root): string
    {
        $raw = $root->getRawOriginal('payment_type');

        if (is_string($raw) && ! ctype_digit($raw)) {
            return $raw;
        }

        return PaymentModel::PAYMENT_TYPES[(int) $raw] ?? 'provider';
    }

    /** Rails: payable — the Invoice or PaymentRequest union member. */
    public function payable(PaymentModel $root): ?object
    {
        return $root->payable;
    }

    /** Rails: payment_provider — the PaymentProvider union member. */
    public function paymentProvider(PaymentModel $root): ?\App\Models\PaymentProvider
    {
        return $root->paymentProvider;
    }

    /** Rails: payment_receipt — the at-most-one receipt of the payment. */
    public function paymentReceipt(PaymentModel $root): ?\App\Models\PaymentReceipt
    {
        return $root->paymentReceipt;
    }
}
